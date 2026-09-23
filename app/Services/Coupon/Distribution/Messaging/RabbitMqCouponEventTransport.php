<?php

namespace App\Services\Coupon\Distribution\Messaging;

use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Production AMQP transport (php-amqplib).
 *
 * Guarantees: durable topic exchange + durable queues, persistent messages,
 * publisher confirms, manual acks with prefetch, TTL retry queues with
 * dead-letter routing, per-queue DLQs. Connection is lazy and recovered
 * per operation — a down broker surfaces as BrokerUnreachableException so
 * the outbox keeps rows pending instead of losing events.
 */
class RabbitMqCouponEventTransport implements CouponEventTransport
{
    private AMQPStreamConnection|AMQPSSLConnection|null $connection = null;
    private ?AMQPChannel $channel = null;

    public function publish(CouponEventEnvelope $envelope): void
    {
        $channel = $this->channel();
        $attempt = max(1, (int) ($envelope->attempt ?? 1));

        $message = new AMQPMessage($envelope->toJson(), [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'message_id' => $envelope->eventId,
            'timestamp' => time(),
            'app_id' => 'meem-coupon-distribution',
            'headers' => new AMQPTable([
                'x-attempt' => $attempt,
                'x-event-type' => $envelope->eventType,
                'x-event-version' => $envelope->version,
                'x-correlation-id' => $envelope->correlationId,
            ]),
        ]);

        $channel->basic_publish(
            $message,
            RabbitMqTopology::exchange(),
            RabbitMqTopology::routingKeyFor($envelope->eventType)
        );

        if (config('rabbitmq.publisher_confirms', true)) {
            $channel->wait_for_pending_acks((int) config('rabbitmq.confirm_timeout', 5));
        }
    }

    public function consume(string $queue, callable $handler, int $maxMessages = 0, int $maxSeconds = 0): int
    {
        $physical = RabbitMqTopology::queueFor($queue);
        $channel = $this->channel();
        $channel->basic_qos(null, (int) config('rabbitmq.prefetch', 10), null);

        $processed = 0;
        $deadline = $maxSeconds > 0 ? microtime(true) + $maxSeconds : 0;
        $stopped = false;

        $callback = function (AMQPMessage $message) use ($handler, $queue, &$processed, $maxMessages, $deadline, &$stopped): void {
            // Headerless messages (foreign publishes, redeliveries) must
            // degrade to empty headers, never crash the consumer: attempt
            // tracking falls back to the envelope/body defaults downstream.
            $rawHeaders = $message->has('application_headers')
                ? $message->get('application_headers')
                : null;

            $headers = [];
            if ($rawHeaders instanceof AMQPTable) {
                $headers = $rawHeaders->getNativeData();
            }

            $outcome = (int) $handler([
                'body' => $message->getBody(),
                'headers' => is_array($headers) ? $headers : [],
                'redelivered' => $message->isRedelivered(),
            ]);

            $processed++;

            if ($outcome === ConsumeResult::RETRY) {
                $this->scheduleRetry($message, $queue, $headers);
            } elseif ($outcome === ConsumeResult::DEAD_LETTER) {
                // Reject without requeue: DLX routes to the per-queue DLQ.
                $message->reject(false);
            } else {
                $message->ack();
            }

            if ($outcome === ConsumeResult::STOP) {
                $stopped = true;
            }

            if ($stopped || ($maxMessages > 0 && $processed >= $maxMessages)) {
                $message->getChannel()->basic_cancel($message->getConsumerTag());
            }
        };

        $channel->basic_consume($physical, '', false, false, false, false, $callback);

        while ($channel->is_consuming()) {
            $timeout = 1.0;

            if ($deadline > 0) {
                $remaining = $deadline - microtime(true);

                if ($remaining <= 0) {
                    break;
                }

                $timeout = min($timeout, $remaining);
            }

            // Idle polls surface as AMQPTimeoutException: not an error, just
            // no delivery inside this slice — keep polling until the deadline
            // or message budget is reached.
            try {
                $channel->wait(null, false, $timeout);
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException) {
                continue;
            }

            if ($stopped || ($maxMessages > 0 && $processed >= $maxMessages)) {
                break;
            }
        }

        return $processed;
    }

    public function declareTopology(): void
    {
        $channel = $this->channel();
        $exchange = RabbitMqTopology::exchange();
        $dlx = RabbitMqTopology::dlxExchange();

        // Dead-letter exchange first (work queues reference it).
        $channel->exchange_declare($dlx, 'direct', false, true, false);

        // Primary topic exchange.
        $channel->exchange_declare($exchange, 'topic', false, true, false);

        foreach (RabbitMqTopology::consumers() as $consumer) {
            $queue = RabbitMqTopology::queueFor($consumer);
            $retry = RabbitMqTopology::retryQueueFor($consumer);
            $dlq = RabbitMqTopology::dlqFor($consumer);

            // Work queue: durable, dead-letters (reject w/o requeue) to DLQ.
            $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([
                'x-dead-letter-exchange' => $dlx,
                'x-dead-letter-routing-key' => $dlq,
            ]));

            foreach (RabbitMqTopology::bindings()[$consumer] as $routingKey) {
                $channel->queue_bind($queue, $exchange, $routingKey);
            }

            // Retry queue: TTL then dead-letter back to the topic exchange
            // under the ORIGINAL routing key. TTL is a ceiling; the actual
            // delay is carried per-message (expiration property).
            $channel->queue_declare($retry, false, true, false, false, false, new AMQPTable([
                'x-dead-letter-exchange' => $exchange,
                'x-message-ttl' => 86400000,
            ]));

            // DLQ bound to the DLX.
            $channel->queue_declare($dlq, false, true, false, false, false);
            $channel->queue_bind($dlq, $dlx, $dlq);
        }
    }

    public function isHealthy(): bool
    {
        try {
            $channel = $this->channel();
            // Passive declare: throws when the exchange is missing.
            $channel->exchange_declare(RabbitMqTopology::exchange(), 'topic', true, true, false);

            return true;
        } catch (\Throwable) {
            $this->close();

            return false;
        }
    }

    public function close(): void
    {
        try {
            $this->channel?->close();
        } catch (\Throwable) {
        }

        try {
            $this->connection?->close();
        } catch (\Throwable) {
        }

        $this->channel = null;
        $this->connection = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel !== null && $this->channel->is_open()) {
            return $this->channel;
        }

        $this->close();

        try {
            $this->connection = $this->openConnection();
            $channel = $this->connection->channel();

            if (config('rabbitmq.publisher_confirms', true)) {
                $channel->confirm_select();
            }

            $this->channel = $channel;

            return $channel;
        } catch (\Throwable $e) {
            $this->close();

            throw new BrokerUnreachableException(
                'RabbitMQ unreachable at '.config('rabbitmq.host').':'.config('rabbitmq.port').' — '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    private function openConnection(): AMQPStreamConnection|AMQPSSLConnection
    {
        $timeout = (int) config('rabbitmq.connection_timeout', 5);
        $rwTimeout = (int) config('rabbitmq.read_write_timeout', 10);
        $heartbeat = (int) config('rabbitmq.heartbeat', 30);

        if (config('rabbitmq.tls', false)) {
            return new AMQPSSLConnection(
                (string) config('rabbitmq.host'),
                (int) config('rabbitmq.port', 5671),
                (string) config('rabbitmq.username'),
                (string) config('rabbitmq.password'),
                (string) config('rabbitmq.vhost', '/'),
                ['verify_peer' => true, 'verify_peer_name' => true],
                ['connection_timeout' => $timeout, 'read_write_timeout' => $rwTimeout, 'heartbeat' => $heartbeat]
            );
        }

        return new AMQPStreamConnection(
            (string) config('rabbitmq.host'),
            (int) config('rabbitmq.port', 5672),
            (string) config('rabbitmq.username'),
            (string) config('rabbitmq.password'),
            (string) config('rabbitmq.vhost', '/'),
            false,
            'AMQPLAIN',
            null,
            'en_US',
            $timeout,
            $rwTimeout,
            null,
            false,
            $heartbeat
        );
    }

    /**
     * Bounded delayed retry: republish to the TTL retry queue with a
     * per-message expiration; the retry queue dead-letters back to the
     * work queue under the original routing key. Attempt counting lives
     * in headers so redeliveries after a crash stay bounded.
     */
    private function scheduleRetry(AMQPMessage $message, string $consumer, array $headers): void
    {
        $attempt = (int) ($headers['x-attempt'] ?? 1) + 1;
        $max = RabbitMqTopology::maxAttemptsFor($consumer);
        $channel = $message->getChannel();

        if ($attempt > $max) {
            // Budget exhausted: reject to DLQ instead of retrying forever.
            $message->reject(false);

            return;
        }

        $delayMs = RabbitMqTopology::retryDelayFor($attempt) * 1000;
        $props = $message->get_properties();
        $props['expiration'] = (string) $delayMs;

        if (isset($props['application_headers']) && $props['application_headers'] instanceof AMQPTable) {
            $native = $props['application_headers']->getNativeData();
            $native['x-attempt'] = $attempt;
            $props['application_headers'] = new AMQPTable(is_array($native) ? $native : []);
        }

        $retry = new AMQPMessage($message->getBody(), $props);
        $channel->basic_publish($retry, '', RabbitMqTopology::retryQueueFor($consumer));
        $message->ack();

        if (config('rabbitmq.publisher_confirms', true)) {
            $channel->wait_for_pending_acks((int) config('rabbitmq.confirm_timeout', 5));
        }
    }
}

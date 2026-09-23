<?php

namespace App\Services\Coupon\Distribution\Messaging;

use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Events\InvalidCouponEventException;

/**
 * In-memory transport for tests and broker-less local runs.
 *
 * Records every published envelope (assertion surface) and replays queued
 * messages through consume() with the same header semantics (x-attempt
 * counting, bounded retry, dead-letter collection) so consumer retry/DLQ
 * logic is proven without a broker.
 */
class FakeCouponEventTransport implements CouponEventTransport
{
    /** @var list<CouponEventEnvelope> */
    public array $published = [];

    /** @var array<string, list<string>> queue => message bodies */
    public array $queues = [];

    /** @var list<array{queue: string, body: string, headers: array}> */
    public array $deadLettered = [];

    public bool $healthy = true;

    public bool $unreachable = false;

    public function publish(CouponEventEnvelope $envelope): void
    {
        if ($this->unreachable) {
            throw new BrokerUnreachableException('Fake transport forced unreachable.');
        }

        $this->published[] = $envelope;

        $consumer = RabbitMqTopology::consumerFor($envelope->eventType);

        if ($consumer !== null) {
            $queue = RabbitMqTopology::queueFor($consumer);
            $this->queues[$queue][] = $envelope->toJson();
        }
    }

    public function consume(string $queue, callable $handler, int $maxMessages = 0, int $maxSeconds = 0): int
    {
        $physical = RabbitMqTopology::queueFor($queue);
        $processed = 0;
        $started = microtime(true);

        while (! empty($this->queues[$physical])) {
            if ($maxMessages > 0 && $processed >= $maxMessages) {
                break;
            }

            if ($maxSeconds > 0 && (microtime(true) - $started) >= $maxSeconds) {
                break;
            }

            $body = array_shift($this->queues[$physical]);

            // Mirror the production consumer: malformed bodies dead-letter
            // instead of escaping as test errors.
            try {
                $envelope = CouponEventEnvelope::fromJson($body);
            } catch (InvalidCouponEventException) {
                $this->deadLettered[] = [
                    'queue' => $physical,
                    'body' => $body,
                    'headers' => ['x-attempt' => 1, 'poison' => true],
                ];
                $processed++;

                continue;
            }

            $outcome = (int) $handler([
                'body' => $body,
                'headers' => [
                    'x-attempt' => $envelope->attempt,
                    'x-event-type' => $envelope->eventType,
                    'x-event-version' => $envelope->version,
                    'x-correlation-id' => $envelope->correlationId,
                ],
                'redelivered' => $envelope->attempt > 1,
            ]);

            $processed++;

            if ($outcome === ConsumeResult::RETRY) {
                $envelope->attempt++;
                $max = RabbitMqTopology::maxAttemptsFor($queue);

                if ($envelope->attempt > $max) {
                    $this->deadLettered[] = [
                        'queue' => $physical,
                        'body' => $envelope->toJson(),
                        'headers' => ['x-attempt' => $envelope->attempt],
                    ];
                } else {
                    $this->queues[$physical][] = $envelope->toJson();
                }
            } elseif ($outcome === ConsumeResult::DEAD_LETTER) {
                $this->deadLettered[] = [
                    'queue' => $physical,
                    'body' => $envelope->toJson(),
                    'headers' => ['x-attempt' => $envelope->attempt],
                ];
            }

            if ($outcome === ConsumeResult::STOP) {
                break;
            }
        }

        return $processed;
    }

    public function declareTopology(): void
    {
        foreach (RabbitMqTopology::queues() as $queue) {
            $this->queues[$queue] ??= [];
        }
    }

    public function isHealthy(): bool
    {
        return $this->healthy && ! $this->unreachable;
    }

    public function close(): void
    {
    }

    public function reset(): void
    {
        $this->published = [];
        $this->queues = [];
        $this->deadLettered = [];
        $this->healthy = true;
        $this->unreachable = false;
    }
}

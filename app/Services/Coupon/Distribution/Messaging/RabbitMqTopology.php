<?php

namespace App\Services\Coupon\Distribution\Messaging;

/**
 * Single source of truth for the coupon RabbitMQ topology.
 *
 * One topic exchange, three work queues, three TTL retry queues, three
 * DLQs, one dead-letter exchange. Application code must resolve names
 * through here — never hardcode exchange/queue/routing strings.
 */
final class RabbitMqTopology
{
    public const CONSUMER_DISTRIBUTION = 'distribution';
    public const CONSUMER_EVALUATION = 'evaluation';
    public const CONSUMER_NOTIFICATIONS = 'notifications';

    public static function exchange(): string
    {
        return (string) config('rabbitmq.exchange', 'coupon.events');
    }

    public static function dlxExchange(): string
    {
        return (string) config('rabbitmq.dlx_exchange', 'coupon.dlx');
    }

    /**
     * Logical consumer name => physical queue name.
     *
     * @return array<string, string>
     */
    public static function queues(): array
    {
        $queues = config('rabbitmq.queues', []);

        return [
            self::CONSUMER_DISTRIBUTION => $queues['distribution'] ?? 'coupon.distribution',
            self::CONSUMER_EVALUATION => $queues['evaluation'] ?? 'coupon.evaluation',
            self::CONSUMER_NOTIFICATIONS => $queues['notifications'] ?? 'coupon.notifications',
        ];
    }

    public static function queueFor(string $consumer): string
    {
        $queues = self::queues();

        if (! isset($queues[$consumer])) {
            throw new \InvalidArgumentException("Unknown coupon consumer [{$consumer}].");
        }

        return $queues[$consumer];
    }

    public static function retryQueueFor(string $consumer): string
    {
        return self::queueFor($consumer).'.retry';
    }

    public static function dlqFor(string $consumer): string
    {
        return self::queueFor($consumer).'.dlq';
    }

    /**
     * Routing keys each work queue binds with (topic bindings).
     *
     * @return array<string, list<string>>
     */
    public static function bindings(): array
    {
        return [
            self::CONSUMER_DISTRIBUTION => [
                'coupon.distribution.start',
                'coupon.distribution.chunk',
            ],
            self::CONSUMER_EVALUATION => [
                'coupon.user.evaluate',
            ],
            self::CONSUMER_NOTIFICATIONS => [
                'coupon.notification.requested',
            ],
        ];
    }

    /**
     * Routing key for an event type. Internal lifecycle events (version 1)
     * use their event type verbatim as the routing key.
     */
    public static function routingKeyFor(string $eventType): string
    {
        return $eventType;
    }

    /**
     * Which logical consumer owns an event type (null = published for
     * observability/routing only, no work queue bound).
     */
    public static function consumerFor(string $eventType): ?string
    {
        foreach (self::bindings() as $consumer => $keys) {
            if (in_array($eventType, $keys, true)) {
                return $consumer;
            }
        }

        return null;
    }

    public static function maxAttemptsFor(string $consumer): int
    {
        $max = config('coupon-distribution.max_attempts', []);

        return (int) ($max[$consumer] ?? 5);
    }

    /**
     * Retry delay in seconds for the UPCOMING 1-based attempt number
     * (attempt 1 is the first delivery, so the first retry is attempt 2
     * and waits the first configured delay; last value repeats).
     */
    public static function retryDelayFor(int $upcomingAttempt): int
    {
        $delays = config('coupon-distribution.retry_delays', [30]);

        if ($delays === []) {
            return 30;
        }

        $index = max(0, $upcomingAttempt - 2);

        return (int) ($delays[min($index, count($delays) - 1)] ?? end($delays));
    }

    /**
     * @return list<string>
     */
    public static function consumers(): array
    {
        return [
            self::CONSUMER_DISTRIBUTION,
            self::CONSUMER_EVALUATION,
            self::CONSUMER_NOTIFICATIONS,
        ];
    }
}

<?php

namespace App\Services\Coupon\Distribution\Events;

/**
 * Versioned coupon domain-event type names (v1 contract).
 *
 * The event type IS the routing key on the `coupon.events` topic exchange.
 * Only the three work types have queue bindings (see RabbitMqTopology);
 * lifecycle types are published for observability, correlation tracing,
 * and future consumers.
 */
final class CouponDistributionEvents
{
    // Lifecycle triggers
    public const COUPON_CREATED = 'coupon.created';
    public const COUPON_ACTIVATED = 'coupon.activated';
    public const COUPON_TARGETING_CHANGED = 'coupon.targeting.changed';
    public const COUPON_DISABLED = 'coupon.disabled';
    public const COUPON_EXPIRED = 'coupon.expired';

    // Distribution pipeline (work queues)
    public const DISTRIBUTION_START = 'coupon.distribution.start';
    public const DISTRIBUTION_CHUNK = 'coupon.distribution.chunk';
    public const USER_EVALUATE = 'coupon.user.evaluate';
    public const NOTIFICATION_REQUESTED = 'coupon.notification.requested';

    // Lifecycle observations
    public const DISTRIBUTION_STARTED = 'coupon.distribution.started';
    public const DISTRIBUTION_COMPLETED = 'coupon.distribution.completed';
    public const DISTRIBUTION_FAILED = 'coupon.distribution.failed';
    public const CHUNK_CREATED = 'coupon.distribution.chunk.created';
    public const CHUNK_COMPLETED = 'coupon.distribution.chunk.completed';
    public const CHUNK_FAILED = 'coupon.distribution.chunk.failed';
    public const EVALUATION_STARTED = 'coupon.user.evaluation.started';
    public const EVALUATION_COMPLETED = 'coupon.user.evaluation.completed';
    public const BECAME_ELIGIBLE = 'coupon.user.became_eligible';
    public const BECAME_INELIGIBLE = 'coupon.user.became_ineligible';
    public const NOTIFICATION_SENT = 'coupon.notification.sent';
    public const NOTIFICATION_FAILED = 'coupon.notification.failed';

    // Existing-flow observations (emitted by established writers)
    public const CLAIMED = 'coupon.claimed';
    public const ASSIGNMENT_CREATED = 'coupon.assignment.created';
    public const RESERVED = 'coupon.reserved';
    public const REDEEMED = 'coupon.redeemed';

    // User / order triggers
    public const USER_REGISTERED = 'user.registered';
    public const USER_ADDRESS_CHANGED = 'user.address.changed';
    public const ORDER_COMPLETED = 'order.completed';
    public const CUSTOMER_METRICS_UPDATED = 'customer.metrics.updated';

    public const VERSION = 1;

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::COUPON_CREATED,
            self::COUPON_ACTIVATED,
            self::COUPON_TARGETING_CHANGED,
            self::COUPON_DISABLED,
            self::COUPON_EXPIRED,
            self::DISTRIBUTION_START,
            self::DISTRIBUTION_CHUNK,
            self::USER_EVALUATE,
            self::NOTIFICATION_REQUESTED,
            self::DISTRIBUTION_STARTED,
            self::DISTRIBUTION_COMPLETED,
            self::DISTRIBUTION_FAILED,
            self::CHUNK_CREATED,
            self::CHUNK_COMPLETED,
            self::CHUNK_FAILED,
            self::EVALUATION_STARTED,
            self::EVALUATION_COMPLETED,
            self::BECAME_ELIGIBLE,
            self::BECAME_INELIGIBLE,
            self::NOTIFICATION_SENT,
            self::NOTIFICATION_FAILED,
            self::CLAIMED,
            self::ASSIGNMENT_CREATED,
            self::RESERVED,
            self::REDEEMED,
            self::USER_REGISTERED,
            self::USER_ADDRESS_CHANGED,
            self::ORDER_COMPLETED,
            self::CUSTOMER_METRICS_UPDATED,
        ];
    }

    public static function isKnown(string $eventType): bool
    {
        return in_array($eventType, self::all(), true);
    }
}

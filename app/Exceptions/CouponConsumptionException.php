<?php

namespace App\Exceptions;

/**
 * Thrown when a coupon discount carried by an order cannot be committed
 * according to business policy at completion time.
 *
 * Fail-closed contract (INV-03): the caller MUST let this bubble so the
 * surrounding transaction rolls back and the order does NOT enter
 * `completed` with an unconsumed/invalid coupon discount.
 */
class CouponConsumptionException extends \RuntimeException
{
    public const REASON_QUOTA_EXHAUSTED = 'quota_exhausted';
    public const REASON_NOT_ASSIGNED = 'not_assigned';
    public const REASON_ALREADY_USED = 'already_used';
    public const REASON_NOT_ELIGIBLE = 'not_eligible';
    public const REASON_NO_CAPACITY = 'no_capacity';
    public const REASON_COUPON_MISSING = 'coupon_missing';
    public const REASON_CLAIM_REQUIRED = 'claim_required';

    public function __construct(
        public readonly string $reason,
        string $message = '',
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : "Coupon consumption failed: {$reason}", 0, $previous);
    }
}

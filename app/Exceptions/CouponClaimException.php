<?php

namespace App\Exceptions;

use Exception;

class CouponClaimException extends Exception
{
    public const REASON_ALREADY_CLAIMED = 'already_claimed';
    public const REASON_NOT_ELIGIBLE = 'not_eligible';
    public const REASON_CLAIM_NOT_REQUIRED = 'claim_not_required';
    public const REASON_NO_TARGETING = 'no_targeting';
    public const REASON_MAX_CLAIMS_REACHED = 'max_claims_reached';

    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function alreadyClaimed(int $couponId, int $userId): self
    {
        return new self(
            "User {$userId} has already claimed coupon {$couponId}",
            self::REASON_ALREADY_CLAIMED,
            ['coupon_id' => $couponId, 'user_id' => $userId],
        );
    }

    public static function notEligible(int $couponId, int $userId, array $failedRules): self
    {
        return new self(
            "User {$userId} is not eligible for coupon {$couponId}",
            self::REASON_NOT_ELIGIBLE,
            ['coupon_id' => $couponId, 'user_id' => $userId, 'failed_rules' => $failedRules],
        );
    }

    public static function claimNotRequired(int $couponId): self
    {
        return new self(
            "Coupon {$couponId} does not require claim",
            self::REASON_CLAIM_NOT_REQUIRED,
            ['coupon_id' => $couponId],
        );
    }

    public static function noTargeting(int $couponId): self
    {
        return new self(
            "Coupon {$couponId} has no targeting configuration",
            self::REASON_NO_TARGETING,
            ['coupon_id' => $couponId],
        );
    }

    public static function maxClaimsReached(int $couponId, int $userId, int $maxClaims): self
    {
        return new self(
            "Coupon {$couponId} has reached its total claim limit ({$maxClaims} claims across all users)",
            self::REASON_MAX_CLAIMS_REACHED,
            ['coupon_id' => $couponId, 'user_id' => $userId, 'max_claims' => $maxClaims],
        );
    }
}

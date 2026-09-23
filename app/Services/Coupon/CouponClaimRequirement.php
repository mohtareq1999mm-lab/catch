<?php

namespace App\Services\Coupon;

/**
 * Single exposure point for the coupon claim requirement.
 *
 * Source of truth: `coupon_targetings.require_claim` (boolean cast on the
 * model). This helper exposes that business truth to notification payloads
 * and API presenters — it never derives the value from targeting mode,
 * assignment existence, coupon availability, or notification type.
 *
 * Semantics:
 * - true  = the user must hold an ACTIVE unexpired claim (POST
 *           /coupons/{id}/claim) before the coupon can be used. Enforced by
 *           CouponClaimService::claim() and CouponOrchestrator::validate().
 * - false = no Claim step is required. This includes coupons WITHOUT a
 *           targeting row (claim() refuses them with `no_targeting`, and the
 *           orchestrator treats targeting-less coupons as pure assignment).
 */
class CouponClaimRequirement
{
    public static function forCoupon($coupon): bool
    {
        $targeting = $coupon?->targeting ?? null;

        return (bool) ($targeting?->require_claim ?? false);
    }
}

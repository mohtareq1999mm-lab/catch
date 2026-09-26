<?php

namespace App\Services\Coupon\Discovery;

use App\Services\Coupon\CouponClaimRequirement;
use App\Services\Coupon\Eligibility\EligibilityEngine;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\User;

/**
 * Canonical customer-facing coupon discovery policy.
 *
 * THE single decision point for visibility, eligibility, claim requirement,
 * and code exposure. Every customer coupon listing endpoint consumes this —
 * no endpoint may implement its own interpretation.
 *
 * Business matrix (authenticated customer):
 * - public (no targeting, no assignments)                    → SHOW, code SHOWN
 * - targeted + eligible + requires_claim=false               → SHOW, code SHOWN
 * - targeted + eligible + requires_claim=true                → SHOW, code HIDDEN
 *   (the code is revealed by the Claim operation itself)
 * - targeted + not eligible                                  → HIDDEN
 * - assignment-only (assignments, no targeting)              → HIDDEN here
 *   (discovered via "My Coupons", which exposes owner codes)
 *
 * Guests: pure-public coupons only, codes hidden (CP-02: the
 * unauthenticated listing never exposes redeemable codes). Targeted coupons
 * need identity — no guest targeting is invented.
 *
 * Admin APIs are explicitly out of scope: they may expose codes/config.
 */
class CouponDiscoveryPolicy
{
    public function __construct(
        private readonly EligibilityEngine $engine,
    ) {}

    /**
     * @return array{visibility: 'public'|'targeted'|null, requires_claim: bool, eligible: bool, can_expose_code: bool, include: bool}
     */
    public function decide(Coupon $coupon, ?User $user): array
    {
        $targeting = $coupon->targeting;
        $requiresClaim = CouponClaimRequirement::forCoupon($coupon);

        if ($targeting !== null) {
            // Targeted coupons need an identity-bound Engine verdict.
            $eligible = $user !== null && $this->engine->evaluate($coupon, $user)->isEligible;
            $visibility = 'targeted';
        } else {
            $hasAssignments = $coupon->relationLoaded('assignments')
                ? $coupon->assignments->isNotEmpty()
                : $coupon->assignments()->exists();

            if ($hasAssignments) {
                return [
                    'visibility' => null,
                    'requires_claim' => false,
                    'eligible' => false,
                    'can_expose_code' => false,
                    'include' => false,
                ];
            }

            // Public: the Engine trivially passes targeting-less coupons
            // (no_targeting), so no evaluation — and no identity — is needed.
            $visibility = 'public';
            $eligible = true;
        }

        return [
            'visibility' => $visibility,
            'requires_claim' => $requiresClaim,
            'eligible' => $eligible,
            'can_expose_code' => $user !== null && ($visibility === 'public' || ! $requiresClaim),
            'include' => $eligible,
        ];
    }
}

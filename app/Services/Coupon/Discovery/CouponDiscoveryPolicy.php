<?php

namespace App\Services\Coupon\Discovery;

use App\Services\Coupon\CouponClaimRequirement;
use App\Services\Coupon\Eligibility\EligibilityEngine;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponClaim;
use Marvel\Database\Models\User;

/**
 * Canonical customer-facing coupon discovery policy.
 *
 * THE single decision point for visibility, eligibility, claim requirement,
 * claim status, action, and code exposure. Every customer coupon listing
 * endpoint consumes this — no endpoint, resource, or controller may
 * implement its own interpretation. Resources render; they never decide.
 *
 * Three visibility categories (never conflated):
 * - targeted:       a targeting configuration exists (takes precedence).
 * - assignment-only: assignments exist but no targeting (personal grants —
 *                   private to assignees via My Coupons, never the catalog).
 * - public:         neither targeting nor assignments.
 *
 * VISIBILITY ≠ ELIGIBILITY ≠ USABILITY: the general catalog shows public +
 * targeted regardless of eligibility; eligibility only shapes code/action.
 * Inclusion stays with the consumer: the catalog drops assignment-only
 * rows; the personalized feed additionally drops ineligible rows.
 *
 * Guests: public + targeted rows listed, codes always hidden (CP-02),
 * no usable action, targeted never eligible. No guest targeting invented.
 *
 * Admin APIs are explicitly out of scope: they may expose codes/config.
 */
class CouponDiscoveryPolicy
{
    public function __construct(
        private readonly EligibilityEngine $engine,
    ) {}

    /**
     * @return array{visibility: 'public'|'targeted'|'assignment-only', requires_claim: bool, eligible: bool, claim_status: 'redeemed'|'claimed'|'not_required'|'claimable', action: 'apply'|'claim'|null, can_expose_code: bool}
     */
    public function decide(Coupon $coupon, ?User $user, ?CouponClaim $claim = null): array
    {
        $targeting = $coupon->targeting;
        $requiresClaim = CouponClaimRequirement::forCoupon($coupon);

        if ($targeting !== null) {
            $visibility = 'targeted';
        } else {
            $hasAssignments = $coupon->relationLoaded('assignments')
                ? $coupon->assignments->isNotEmpty()
                : $coupon->assignments()->exists();
            $visibility = $hasAssignments ? 'assignment-only' : 'public';
        }

        if ($user !== null) {
            // Uniform Engine verdict across modes; apply-time gates
            // (assignment, claim) stay authoritative downstream.
            $eligible = $this->engine->evaluate($coupon, $user)->isEligible;
        } else {
            $eligible = $visibility === 'public';
        }

        $state = CouponAction::resolve($eligible, $requiresClaim, $claim);

        // Guests browse only: no claim/apply affordance without identity.
        $action = $user !== null ? $state['action'] : null;

        return [
            'visibility' => $visibility,
            'requires_claim' => $requiresClaim,
            'eligible' => $eligible,
            'claim_status' => $state['claim_status'],
            'action' => $action,
            'can_expose_code' => $user !== null && $eligible
                && $visibility !== 'assignment-only'
                && ($visibility === 'public' || ! $requiresClaim),
        ];
    }
}

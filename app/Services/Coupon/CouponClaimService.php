<?php

namespace App\Services\Coupon;

use App\Enums\CouponClaimStatus;
use App\Exceptions\CouponClaimException;
use App\Services\Coupon\Eligibility\EligibilityEngine;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponClaim;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\User;

class CouponClaimService
{
    public function __construct(
        private readonly EligibilityEngine $eligibilityEngine,
    ) {}

    /**
     * Claim a coupon for a user.
     *
     * Phase 2 Concurrency Strategy: Parent-row serialization via CouponTargeting FOR UPDATE lock.
     * 
     * Lifecycle States:
     * - ACTIVE: Current valid claim (counts toward capacity, blocks duplicate active claims)
     * - EXPIRED: TTL reached or manual expiration (releases capacity, allows re-claim)
     * - REDEEMED: Order completed with coupon (permanent record, counts toward capacity)
     *
     * Uniqueness Enforcement:
     * - Database constraint: UNIQUE(coupon_id, user_id) WHERE status='active' (MySQL production)
     * - Application check: Fallback for SQLite development environment
     *
     * @throws CouponClaimException
     */
    public function claim(Coupon $coupon, User $user): CouponClaim
    {
        return DB::transaction(function () use ($coupon, $user) {
            // CRITICAL: Acquire parent-row lock on CouponTargeting
            // This serializes all claim attempts for this coupon
            $targeting = CouponTargeting::query()
                ->where('coupon_id', $coupon->getKey())
                ->lockForUpdate()
                ->first();

            if (!$targeting) {
                throw CouponClaimException::noTargeting($coupon->getKey());
            }

            if (!$targeting->require_claim) {
                throw CouponClaimException::claimNotRequired($coupon->getKey());
            }

            // Phase 2: Check for existing ACTIVE claim only
            // Expired/redeemed claims allow re-claiming
            $existingActiveClaim = CouponClaim::query()
                ->where('coupon_id', $coupon->getKey())
                ->where('user_id', $user->getKey())
                ->where('status', CouponClaimStatus::ACTIVE)
                ->exists();

            if ($existingActiveClaim) {
                throw CouponClaimException::alreadyClaimed($coupon->getKey(), $user->getKey());
            }

            // Phase 2: Count active + redeemed claims only (expired claims release capacity)
            // max_claims = first-N semantics across all users
            if ($targeting->max_claims !== null) {
                $occupiedSlots = CouponClaim::query()
                    ->where('coupon_id', $coupon->getKey())
                    ->whereIn('status', [
                        CouponClaimStatus::ACTIVE,
                        CouponClaimStatus::REDEEMED,
                    ])
                    ->count();

                if ($occupiedSlots >= $targeting->max_claims) {
                    throw CouponClaimException::maxClaimsReached(
                        $coupon->getKey(),
                        $user->getKey(),
                        $targeting->max_claims
                    );
                }
            }

            // Evaluate eligibility
            $eligibilityResult = $this->eligibilityEngine->evaluate($coupon, $user);

            if (!$eligibilityResult->isEligible) {
                throw CouponClaimException::notEligible(
                    $coupon->getKey(),
                    $user->getKey(),
                    $eligibilityResult->failedRules
                );
            }

            // Phase 2: Create claim with lifecycle state and TTL
            $expiresAt = $targeting->claim_ttl_hours
                ? now()->addHours($targeting->claim_ttl_hours)
                : null;

            $claim = CouponClaim::create([
                'coupon_id' => $coupon->getKey(),
                'user_id' => $user->getKey(),
                'status' => CouponClaimStatus::ACTIVE,
                'claimed_at' => now(),
                'expires_at' => $expiresAt,
                'eligibility_snapshot' => [
                    'passed_rules' => $eligibilityResult->passedRules,
                    'evaluated_metrics' => $eligibilityResult->evaluatedMetrics,
                    'evaluated_at' => now()->toIso8601String(),
                ],
            ]);

            return $claim;
        });
    }

    /**
     * Check if user has an ACTIVE claim for a coupon.
     * Phase 2: Checks active status only, expired/redeemed don't block re-claims.
     */
    public function hasClaimed(Coupon $coupon, User $user): bool
    {
        return CouponClaim::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', CouponClaimStatus::ACTIVE)
            ->exists();
    }

    /**
     * Get user's ACTIVE claim for a coupon if it exists.
     * Phase 2: Returns only active claims, expired/redeemed claims excluded.
     */
    public function getClaim(Coupon $coupon, User $user): ?CouponClaim
    {
        return CouponClaim::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', CouponClaimStatus::ACTIVE)
            ->first();
    }

    /**
     * Mark a claim as redeemed when order completes.
     * Phase 2: Transition ACTIVE → REDEEMED (permanent, counts toward capacity).
     */
    public function markRedeemed(CouponClaim $claim): void
    {
        if ($claim->status !== CouponClaimStatus::ACTIVE) {
            throw CouponClaimException::cannotRedeemNonActiveClaim($claim->getKey());
        }

        $claim->update([
            'status' => CouponClaimStatus::REDEEMED,
            'redeemed_at' => now(),
        ]);
    }

    /**
     * Expire claims that have passed their TTL.
     * Phase 2: Transition ACTIVE → EXPIRED (releases capacity, allows re-claim).
     * 
     * Called by scheduled command (e.g., hourly cron job).
     * Returns count of expired claims.
     */
    public function expireExpiredClaims(): int
    {
        return CouponClaim::query()
            ->where('status', CouponClaimStatus::ACTIVE)
            ->where('expires_at', '<=', now())
            ->whereNotNull('expires_at')
            ->update([
                'status' => CouponClaimStatus::EXPIRED,
            ]);
    }
}

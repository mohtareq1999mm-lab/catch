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
     * Lifecycle States (F-16 enforced):
     * - ACTIVE (unexpired): Current valid claim (counts toward capacity, blocks duplicate).
     * - REDEEMED: Order completed with coupon (permanent, counts toward capacity, BLOCKS re-claim).
     * - EXPIRED (or time-expired ACTIVE): releases capacity, allows re-claim.
     *
     * Uniqueness Enforcement:
     * - Application checks for ACTIVE + REDEEMED under parent-row lock.
     * - DB unique(coupon_id,user_id) was dropped; duplicate ACTIVE detection
     *   is covered by coupons:reconcile `duplicate_active_claims` detector.
     *
     * Lock ordering (F-13): CouponTargeting → CouponClaim → (eligibility reads).
     *
     * @throws CouponClaimException
     */
    public function claim(Coupon $coupon, User $user): CouponClaim
    {
        // P2: bounded deadlock retry (3). Safe: any retried attempt rolled
        // back fully, and the claim insert is guarded by the ACTIVE check +
        // parent-row lock, so a retry can never double-create.
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

            // F-16: single-use claim lifecycle.
            // ACTIVE (unexpired) → cannot claim again (duplicate).
            // REDEEMED → cannot claim again (already consumed; re-claim would
            //   occupy another max_claims slot while coupon_usages unique blocks
            //   reuse — the exact bug). EXPIRED (or time-expired ACTIVE) releases
            //   capacity and MAY claim again.
            $existingActiveClaim = CouponClaim::query()
                ->where('coupon_id', $coupon->getKey())
                ->where('user_id', $user->getKey())
                ->where('status', CouponClaimStatus::ACTIVE)
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
                })
                ->exists();

            if ($existingActiveClaim) {
                throw CouponClaimException::alreadyClaimed($coupon->getKey(), $user->getKey());
            }

            $hasRedeemed = CouponClaim::query()
                ->where('coupon_id', $coupon->getKey())
                ->where('user_id', $user->getKey())
                ->where('status', CouponClaimStatus::REDEEMED)
                ->exists();

            if ($hasRedeemed) {
                throw CouponClaimException::alreadyClaimed($coupon->getKey(), $user->getKey());
            }

            // Phase 2: Count active + redeemed claims only (expired claims release capacity)
            // max_claims = first-N semantics across all users
            if ($targeting->max_claims !== null) {
                $occupiedSlots = CouponClaim::query()
                    ->where('coupon_id', $coupon->getKey())
                    ->where(function ($q) {
                        // REDEEMED claims always count (permanent records)
                        $q->where('status', CouponClaimStatus::REDEEMED)
                          // ACTIVE claims count only if not expired by time
                          ->orWhere(function ($q2) {
                              $q2->where('status', CouponClaimStatus::ACTIVE)
                                 ->where(function ($q3) {
                                     $q3->whereNull('expires_at')
                                        ->orWhere('expires_at', '>', now());
                                 });
                          });
                    })
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
        }, 3);
    }

    /**
     * Check if user has an ACTIVE claim for a coupon.
     * Phase 2: Checks active status only, expired/redeemed don't block re-claims.
     * FIX #4: Also validate claim hasn't expired (checks expires_at)
     */
    public function hasClaimed(Coupon $coupon, User $user): bool
    {
        return CouponClaim::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', CouponClaimStatus::ACTIVE)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    /**
     * Get user's ACTIVE claim for a coupon if it exists.
     * Phase 2: Returns only active claims, expired/redeemed claims excluded.
     * FIX #4: Also validate claim hasn't expired (checks expires_at)
     */
    public function getClaim(Coupon $coupon, User $user): ?CouponClaim
    {
        return CouponClaim::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', CouponClaimStatus::ACTIVE)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
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

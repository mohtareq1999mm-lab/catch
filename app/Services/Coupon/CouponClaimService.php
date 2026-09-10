<?php

namespace App\Services\Coupon;

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
     * Concurrency Strategy: Parent-row serialization via CouponTargeting FOR UPDATE lock.
     * This ensures only one claim per user per coupon can proceed at a time, leveraging
     * the UNIQUE(coupon_id, user_id) constraint as the atomic guard.
     *
     * Lifecycle: Claim = persistent intent + lifetime slot reservation.
     * NOT checkout reservation, NOT redemption.
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

            // Check if user has already claimed (before eligibility evaluation for performance)
            $existingClaim = CouponClaim::query()
                ->where('coupon_id', $coupon->getKey())
                ->where('user_id', $user->getKey())
                ->first();

            if ($existingClaim) {
                throw CouponClaimException::alreadyClaimed($coupon->getKey(), $user->getKey());
            }

            // Check TOTAL claims (coupon capacity across all users)
            // max_claims = total slots available (e.g., "first 100 users")
            // UNIQUE(coupon_id, user_id) = one claim per user
            if ($targeting->max_claims !== null) {
                $totalClaims = CouponClaim::query()
                    ->where('coupon_id', $coupon->getKey())
                    ->count();

                if ($totalClaims >= $targeting->max_claims) {
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

            // Create claim record (UNIQUE constraint provides atomic guard)
            $claim = CouponClaim::create([
                'coupon_id' => $coupon->getKey(),
                'user_id' => $user->getKey(),
                'claimed_at' => now(),
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
     * Check if user has claimed a coupon.
     */
    public function hasClaimed(Coupon $coupon, User $user): bool
    {
        return CouponClaim::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('user_id', $user->getKey())
            ->exists();
    }

    /**
     * Get user's claim for a coupon if it exists.
     */
    public function getClaim(Coupon $coupon, User $user): ?CouponClaim
    {
        return CouponClaim::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('user_id', $user->getKey())
            ->first();
    }
}

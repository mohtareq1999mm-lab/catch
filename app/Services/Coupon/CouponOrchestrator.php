<?php

namespace App\Services\Coupon;

use Illuminate\Support\Collection;
use App\Services\Coupon\Eligibility\EligibilityEngine;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponClaim;
use Marvel\Database\Models\User;

class CouponOrchestrator
{
    public static function validateByCode(string $code, ?User $user = null, ?Collection $items = null, array $context = []): array
    {
        // CP-09: canonical lookup (case-insensitive, trimmed).
        $coupon = Coupon::byCode($code)->first();

        if (!$coupon) {
            return self::invalid('not_found', __('coupon.not_found'));
        }

        return self::validate($coupon, $user, $items, $context);
    }

    public static function validate(Coupon $coupon, ?User $user = null, ?Collection $items = null, array $context = []): array
    {
        // F-03: distinguish expected business rejection from infra failure.
        // - Business rejections (not found, claim required, not assigned, expired,
        //   limit reached, product ineligible) return invalid[] (controlled 4xx).
        // - Unexpected infra/programming failures (DB down, programming error)
        //   MUST bubble so callers return 500, never silently become "invalid coupon".
        // Only the missing-table case (test env without targeting migration) is
        // safely skippable, detected via Schema::hasTable, not broad catch.
        //
        // $context carries evaluation inputs that are not identity.
        // NOTE: area_in no longer reads $context (saved-address rule);
        // any 'governorate_id' key present is ignored by evaluation.
        $targeting = null;
        if ($user && method_exists($coupon, 'targeting')) {
            $hasTargetingTable = true;
            try {
                $hasTargetingTable = \Illuminate\Support\Facades\Schema::hasTable('coupon_targetings');
            } catch (\Throwable $e) {
                report($e);
                throw $e;
            }

            if ($hasTargetingTable) {
                $targeting = $coupon->targeting;

                if ($targeting && $targeting->require_claim) {
                    // F-16: REDEEMED can never satisfy require_claim and must not
                    // invite a reclaim that claim() will refuse. Surface as
                    // already_used (fail-closed) instead of claim_required.
                    $hasRedeemed = CouponClaim::query()
                        ->where('coupon_id', $coupon->getKey())
                        ->where('user_id', $user->getKey())
                        ->where('status', \App\Enums\CouponClaimStatus::REDEEMED)
                        ->exists();

                    if ($hasRedeemed) {
                        return self::invalid('already_used', __('coupon.already_used'));
                    }

                    // Phase 2 fix: Check for current usable ACTIVE claim (not historical)
                    // Expired claims do NOT satisfy require_claim
                    $hasActiveClaim = CouponClaim::query()
                        ->where('coupon_id', $coupon->getKey())
                        ->where('user_id', $user->getKey())
                        ->where('status', \App\Enums\CouponClaimStatus::ACTIVE)
                        ->where(function ($q) {
                            $q->whereNull('expires_at')
                              ->orWhere('expires_at', '>', now());
                        })
                        ->exists();

                    if (!$hasActiveClaim) {
                        return self::invalid('claim_required', __('coupon.claim_required'));
                    }
                }
            }
        }

        if ($user) {
            // Targeting mode drives which authority gates apply/checkout.
            // Legacy coupons without a targeting row behave as 'assignment'
            // (backward compatible: assignment validator is authoritative).
            $mode = $targeting?->mode ?? 'assignment';

            // Checkout-revalidation fix: a rule that is true at claim/apply
            // time but false at checkout/payment MUST be rejected here.
            // The engine is therefore authoritative for every mode that
            // includes a rule tree — apply/checkout/payment never trust
            // earlier results.
            $eligibility = null;
            if (in_array($mode, ['dynamic', 'assignment_and_dynamic', 'assignment_or_dynamic'], true)) {
                $eligibility = app(EligibilityEngine::class)->evaluate($coupon, $user, $context);
            }

            // Assignment gate is skipped only for pure dynamic mode (the tree
            // is the sole authority there); for OR it is one sufficient path.
            $assignmentResult = null;
            if ($mode !== 'dynamic') {
                $assignmentResult = CouponAssignmentValidator::validate($coupon, $user);
            }

            if ($mode === 'dynamic') {
                // Static reasons (disabled/expired/limits) stay precise and
                // are reported before the generic eligibility rejection.
                $validation = CouponValidator::validate($coupon, $user, $items);
                if (!$validation['valid']) {
                    return $validation;
                }

                if (!$eligibility->isEligible) {
                    return self::invalid('not_eligible', __('coupon.not_eligible'));
                }
            } elseif ($mode === 'assignment_or_dynamic') {
                // The assignment path counts only when the coupon grants
                // assignments AND this user holds a valid one; otherwise a
                // coupon with zero assignment rows would bypass the tree.
                $assignmentOk = ($assignmentResult['valid'] ?? false) === true
                    && !empty($assignmentResult['has_assignments']);

                if (!$assignmentOk && !$eligibility->isEligible) {
                    // Neither path satisfied. Prefer the assignment reason
                    // when the coupon actually grants assignments.
                    if (!empty($assignmentResult['has_assignments']) && isset($assignmentResult['reason'])) {
                        return [
                            'valid' => false,
                            'reason' => $assignmentResult['reason'],
                            'message' => $assignmentResult['message'],
                            'coupon' => null,
                        ];
                    }

                    return self::invalid('not_eligible', __('coupon.not_eligible'));
                }

                if ($assignmentOk && !empty($assignmentResult['has_assignments'])) {
                    $rejection = self::rejectIfPubliclyUsed($coupon, $user);
                    if ($rejection) {
                        return $rejection;
                    }

                    $validation = CouponValidator::validate($coupon, null, $items);
                } else {
                    $validation = CouponValidator::validate($coupon, $user, $items);
                }
            } else {
                // assignment, assignment_and_dynamic, legacy: strict assignment gate.
                if (!$assignmentResult['valid']) {
                    return [
                        'valid' => false,
                        'reason' => $assignmentResult['reason'],
                        'message' => $assignmentResult['message'],
                        'coupon' => null,
                    ];
                }

                if ($mode === 'assignment_and_dynamic' && !$eligibility->isEligible) {
                    return self::invalid('not_eligible', __('coupon.not_eligible'));
                }

                if ($assignmentResult['has_assignments']) {
                    $rejection = self::rejectIfPubliclyUsed($coupon, $user);
                    if ($rejection) {
                        return $rejection;
                    }

                    $validation = CouponValidator::validate($coupon, null, $items);
                } else {
                    $validation = CouponValidator::validate($coupon, $user, $items);
                }
            }
        } else {
            $validation = CouponValidator::validate($coupon, null, $items);
        }

        if (!$validation['valid']) {
            return $validation;
        }

        return self::valid($coupon);
    }

    /**
     * POLICY 1: an assigned grant MUST NOT bypass the user's public lifetime
     * history for the same logical coupon. A prior public consumption still
     * blocks reuse through the assigned path.
     *
     * @return array|null invalid[] rejection or null when clear.
     */
    private static function rejectIfPubliclyUsed(Coupon $coupon, User $user): ?array
    {
        $publiclyUsed = \Marvel\Database\Models\CouponUsage::where('coupon_id', $coupon->id)
            ->where('user_id', $user->id)
            ->whereNotNull('used_at')
            ->exists();

        if ($publiclyUsed) {
            return self::invalid('already_used', __('coupon.already_used'));
        }

        return null;
    }

    private static function valid(Coupon $coupon): array
    {
        return [
            'valid' => true,
            'reason' => null,
            'message' => null,
            'coupon' => $coupon,
        ];
    }

    private static function invalid(string $reason, string $message): array
    {
        return [
            'valid' => false,
            'reason' => $reason,
            'message' => $message,
            'coupon' => null,
        ];
    }
}

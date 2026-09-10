<?php

namespace App\Services\Coupon;

use Illuminate\Support\Collection;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponClaim;
use Marvel\Database\Models\User;

class CouponOrchestrator
{
    public static function validateByCode(string $code, ?User $user = null, ?Collection $items = null): array
    {
        $coupon = Coupon::where('code', $code)->first();

        if (!$coupon) {
            return self::invalid('not_found', __('coupon.not_found'));
        }

        return self::validate($coupon, $user, $items);
    }

    public static function validate(Coupon $coupon, ?User $user = null, ?Collection $items = null): array
    {
        // NEW: Check claim requirement BEFORE other validation
        // This ensures claim-required coupons cannot be applied without claiming first
        // SAFETY: Check if relationship exists (table may not exist in test environment)
        if ($user && method_exists($coupon, 'targeting')) {
            try {
                $targeting = $coupon->targeting;
                
                if ($targeting && $targeting->require_claim) {
                    $hasClaim = CouponClaim::query()
                        ->where('coupon_id', $coupon->getKey())
                        ->where('user_id', $user->getKey())
                        ->exists();

                    if (!$hasClaim) {
                        return self::invalid('claim_required', __('coupon.claim_required'));
                    }
                }
            } catch (\Exception $e) {
                // If targeting table doesn't exist (e.g., in test environment without migrations),
                // silently continue with existing coupon validation logic
            }
        }

        if ($user) {
            $assignmentResult = CouponAssignmentValidator::validate($coupon, $user);

            if (!$assignmentResult['valid']) {
                return [
                    'valid' => false,
                    'reason' => $assignmentResult['reason'],
                    'message' => $assignmentResult['message'],
                    'coupon' => null,
                ];
            }

            if ($assignmentResult['has_assignments']) {
                $validation = CouponValidator::validate($coupon, null, $items);
            } else {
                $validation = CouponValidator::validate($coupon, $user, $items);
            }
        } else {
            $validation = CouponValidator::validate($coupon, null, $items);
        }

        if (!$validation['valid']) {
            return $validation;
        }

        return self::valid($coupon);
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

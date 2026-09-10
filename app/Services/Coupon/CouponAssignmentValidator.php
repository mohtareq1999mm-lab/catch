<?php

namespace App\Services\Coupon;

use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\User;

class CouponAssignmentValidator
{
    public static function validate(Coupon $coupon, User $user): array
    {
        $hasAssignments = $coupon->assignments()->exists();

        if (!$hasAssignments) {
            return [
                'has_assignments' => false,
                'valid' => true,
            ];
        }

        $assignment = CouponAssignment::where('coupon_id', $coupon->id)
            ->where('user_id', $user->id)
            ->first();

        if (!$assignment) {
            return self::invalid('not_assigned', __('coupon.not_assigned'));
        }

        if ($assignment->expires_at && $assignment->expires_at->isPast()) {
            return self::invalid('assignment_expired', __('coupon.assignment_expired'));
        }

        if ($assignment->used >= $assignment->max_uses) {
            return self::invalid('usage_quota_exceeded', __('coupon.usage_quota_exceeded'));
        }

        return [
            'has_assignments' => true,
            'valid' => true,
            'assignment' => $assignment,
        ];
    }

    private static function invalid(string $reason, string $message): array
    {
        return [
            'has_assignments' => true,
            'valid' => false,
            'reason' => $reason,
            'message' => $message,
        ];
    }
}

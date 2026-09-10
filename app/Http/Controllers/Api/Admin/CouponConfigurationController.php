<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Marvel\Database\Models\Coupon;
use Marvel\Traits\ApiResponse;

class CouponConfigurationController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware(['auth:sanctum']);
    }

    /**
     * Validate coupon configuration before save
     *
     * POST /api/v1/admin/coupons/validate-configuration
     */
    public function validateConfiguration(Request $request)
    {
        $validated = $request->validate([
            'coupon_type' => 'required|in:public,assigned',
            'limiter' => 'nullable|integer|min:1',
            'max_uses_per_user' => 'nullable|integer|min:1',
        ]);

        $warnings = [];
        $errors = [];
        $recommendations = [];

        $couponType = $validated['coupon_type'];
        $limiter = $validated['limiter'] ?? null;
        $maxUsesPerUser = $validated['max_uses_per_user'] ?? 1;

        // Rule 1: Public coupons are ALWAYS single-use per user
        if ($couponType === 'public') {
            if ($maxUsesPerUser > 1) {
                $errors[] = [
                    'field' => 'max_uses_per_user',
                    'message' => 'Public coupons only support single use per customer.',
                    'explanation' => 'The coupon_usages table enforces UNIQUE(coupon_id, user_id), preventing repeat use. For multi-use, switch to "assigned" coupon type.',
                ];
            }

            $limiterDisplay = $limiter ?? 'unlimited';
            $recommendations[] = [
                'title' => 'Public Coupon Behavior',
                'description' => "Each customer can redeem this coupon exactly once. Global capacity: {$limiterDisplay} total redemptions across all customers.",
            ];
        }

        // Rule 2: Assigned coupons support multi-use via max_uses
        if ($couponType === 'assigned') {
            if ($maxUsesPerUser > 1) {
                $recommendations[] = [
                    'title' => 'Multi-Use Configuration',
                    'description' => "Each assigned customer can redeem this coupon up to {$maxUsesPerUser} times. You must create coupon assignments with max_uses={$maxUsesPerUser} for each eligible customer.",
                ];
            }

            if (!$limiter) {
                $warnings[] = [
                    'field' => 'limiter',
                    'message' => 'No global limiter set. Coupon can be used unlimited times (only limited by assignments).',
                ];
            }
        }

        // Rule 3: Limiter check
        if ($limiter && $couponType === 'public') {
            if ($limiter > 1000) {
                $warnings[] = [
                    'field' => 'limiter',
                    'message' => "High global limit ({$limiter}). This allows {$limiter} different customers to redeem (1 per customer).",
                ];
            }
        }

        return $this->apiResponse('Validation completed.', 200, empty($errors), [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'recommendations' => $recommendations,
        ]);
    }

    /**
     * Get coupon usage explanation
     *
     * GET /api/v1/admin/coupons/{id}/usage-info
     */
    public function getUsageInfo($couponId)
    {
        $coupon = Coupon::with(['assignments', 'couponUsages'])->findOrFail($couponId);

        $isPublic = $coupon->isPublic();
        $currentUsage = (int) $coupon->used;
        $globalLimit = $coupon->limiter;

        $assignmentInfo = null;
        if (!$isPublic) {
            $totalAssignments = $coupon->assignments()->count();
            $assignmentsUsed = $coupon->assignments()->where('used', '>', 0)->count();
            $maxUsesPerAssignment = $coupon->assignments()->max('max_uses') ?? 1;

            $assignmentInfo = [
                'total_assignments' => $totalAssignments,
                'assignments_with_usage' => $assignmentsUsed,
                'max_uses_per_user' => (int) $maxUsesPerAssignment,
                'total_possible_redemptions' => $totalAssignments * (int) $maxUsesPerAssignment,
            ];
        }

        return $this->apiResponse('Usage info retrieved.', 200, true, [
            'coupon_code' => $coupon->code,
            'coupon_type' => $isPublic ? 'public' : 'assigned',
            'usage_model' => $coupon->getUsageDescription(),
            'current_usage' => $currentUsage,
            'global_limit' => $globalLimit,
            'remaining_capacity' => $globalLimit !== null ? max(0, (int) $globalLimit - $currentUsage) : 'unlimited',
            'is_multi_use_per_user' => $coupon->isMultiUsePerUser(),
            'assignment_info' => $assignmentInfo,
            'public_usage_count' => $isPublic ? $coupon->couponUsages()->count() : 0,
        ]);
    }

    /**
     * Suggest configuration fix
     *
     * POST /api/v1/admin/coupons/{id}/suggest-fix
     */
    public function suggestFix($couponId, Request $request)
    {
        $coupon = Coupon::with('assignments')->findOrFail($couponId);

        $desiredBehavior = $request->input('desired_behavior'); // 'multi_use_per_user' or 'single_use_per_user'

        if ($desiredBehavior === 'multi_use_per_user') {
            if ($coupon->isPublic()) {
                return $this->apiResponse('Suggestion generated.', 200, true, [
                    'current_issue' => 'Coupon is public (no assignments). Public coupons only support single-use per user.',
                    'recommended_action' => 'convert_to_assigned',
                    'steps' => [
                        '1. Create a list of eligible customers',
                        '2. For each customer, create a coupon_assignment with max_uses=N (e.g., 5)',
                        '3. Customers will be able to redeem N times each',
                    ],
                    'example_code' => "CouponAssignment::create(['coupon_id' => {$coupon->id}, 'user_id' => \$userId, 'max_uses' => 5]);",
                ]);
            } else {
                $currentMaxUses = $coupon->assignments()->max('max_uses') ?? 1;
                return $this->apiResponse('Suggestion generated.', 200, true, [
                    'current_config' => "Assigned coupon with max_uses={$currentMaxUses}",
                    'recommended_action' => $currentMaxUses > 1 ? 'already_configured' : 'update_assignments',
                    'steps' => $currentMaxUses > 1 ? [] : [
                        "Update existing assignments: CouponAssignment::where('coupon_id', {$coupon->id})->update(['max_uses' => 5]);",
                    ],
                ]);
            }
        }

        if ($desiredBehavior === 'single_use_per_user') {
            return $this->apiResponse('Suggestion generated.', 200, true, [
                'current_config' => $coupon->isPublic() ? 'Public (already single-use)' : 'Assigned',
                'recommended_action' => $coupon->isPublic() ? 'no_change_needed' : 'remove_assignments_or_set_max_uses_1',
                'message' => $coupon->isPublic()
                    ? 'Coupon is already configured for single-use per user.'
                    : 'To enforce single-use, either remove all assignments (convert to public) or set max_uses=1 on all assignments.',
            ]);
        }

        return $this->apiResponse('Invalid desired_behavior. Use multi_use_per_user or single_use_per_user.', 400, false);
    }
}

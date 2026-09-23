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

    private function authorizeAdmin(\Illuminate\Http\Request $request): void
    {
        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated.');
        }
        try {
            if (method_exists($user, 'hasPermissionTo') && ($user->hasPermissionTo('view-coupons') || $user->hasPermissionTo('update-coupon') || $user->hasPermissionTo('create-coupon'))) {
                return;
            }
            if (method_exists($user, 'can') && ($user->can('view-coupons') || $user->can('update-coupon'))) {
                return;
            }
            if (($user->type ?? null) === 'admin' || ($user->role ?? null) === 'admin') {
                if (method_exists($user, 'hasPermissionTo')) {
                    abort(403, 'Forbidden. Missing required permission: view-coupons.');
                }
                return;
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
        }
        abort(403, 'Forbidden. Missing required permission: view-coupons.');
    }

    /**
     * Validate coupon configuration before save
     *
     * POST /api/v1/admin/coupons/validate-configuration
     */
    public function validateConfiguration(Request $request)
    {
        $this->authorizeAdmin($request);
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
    public function getUsageInfo(Request $request, $couponId)
    {
        $this->authorizeAdmin($request);
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
     * Suggest configuration fix.
     *
     * POST /api/v1/admin/coupons/{id}/suggest-fix
     *
     * Business-level guidance only: the response contains actionable admin
     * steps and never exposes PHP/Eloquent/SQL implementation details.
     * Nothing is modified — applying a suggestion is a separate admin action
     * through the assignments/targeting APIs referenced in available_actions.
     */
    public function suggestFix($couponId, Request $request)
    {
        $this->authorizeAdmin($request);
        $coupon = Coupon::with('assignments')->findOrFail($couponId);

        $desiredBehavior = $request->input('desired_behavior'); // 'multi_use_per_user' or 'single_use_per_user'

        if (! in_array($desiredBehavior, ['multi_use_per_user', 'single_use_per_user'], true)) {
            return $this->apiResponse('Invalid desired behavior.', 400, false, [
                'allowed_values' => ['multi_use_per_user', 'single_use_per_user'],
            ]);
        }

        $isPublic = $coupon->isPublic();
        $currentMaxUses = $isPublic ? 1 : (int) ($coupon->assignments()->max('max_uses') ?? 1);
        $targeting = $this->safeTargeting($coupon);
        $warnings = $this->claimAndTargetingWarnings($targeting);

        if ($desiredBehavior === 'multi_use_per_user') {
            if ($isPublic) {
                // Public coupons enforce single use per customer
                // (CouponValidator already_used via coupon_usages), so
                // per-customer limits require customer assignments.
                // Creating the first assignment converts public → assigned.
                return $this->apiResponse('Suggestion generated.', 200, true, [
                    'current_state' => 'This coupon is currently public and has no customer assignments. Public coupons allow one use per customer.',
                    'recommended_action' => 'convert_to_assigned',
                    'summary' => 'To give each customer an individual usage limit, the coupon must use customer-specific assignments.',
                    'steps' => [
                        'Select the customers who should receive this coupon.',
                        'Create a coupon assignment for each selected customer.',
                        'Set the maximum number of uses for each customer.',
                        'Verify that the assignments are active and check their expiration settings.',
                        'The selected customers can then use the coupon up to their individual limit.',
                    ],
                    'expected_result' => 'Each assigned customer receives an independent usage limit.',
                    'warnings' => array_merge([
                        'Creating the first assignment converts this coupon from public to assigned. Customers who are not assigned will no longer be able to use it.',
                        'Past public usage history is preserved and still counts against returning customers.',
                    ], $warnings),
                    'available_actions' => [
                        [
                            'label' => 'Create coupon assignment',
                            'method' => 'POST',
                            'path' => "/api/v1/coupons/{$coupon->getKey()}/assignments",
                        ],
                        [
                            'label' => 'List coupon assignments',
                            'method' => 'GET',
                            'path' => "/api/v1/coupons/{$coupon->getKey()}/assignments",
                        ],
                    ],
                ]);
            }

            if ($currentMaxUses > 1) {
                return $this->apiResponse('Suggestion generated.', 200, true, [
                    'current_state' => "This coupon is already configured with up to {$currentMaxUses} uses per customer.",
                    'recommended_action' => 'no_change_needed',
                    'summary' => 'The current configuration already supports the requested behavior.',
                    'steps' => [],
                    'expected_result' => 'Each assigned customer can use the coupon according to their configured usage limit.',
                    'warnings' => $warnings,
                    'available_actions' => [],
                ]);
            }

            return $this->apiResponse('Suggestion generated.', 200, true, [
                'current_state' => 'The current assignments allow one use per customer.',
                'recommended_action' => 'update_assignments',
                'summary' => 'Raise the per-customer usage limit on the existing assignments.',
                'steps' => [
                    'Open the coupon customer assignments list.',
                    'Select the assignments that should support multiple uses.',
                    'Increase the maximum allowed uses for each selected customer.',
                    'Save the changes for each assignment.',
                    'Verify the updated usage limits.',
                ],
                'expected_result' => 'The selected customers can use the coupon multiple times, up to their configured limit.',
                'warnings' => array_merge([
                    'Assignments can only be updated one at a time; there is no bulk update. No automatic change was made.',
                    'Raising the limit never revokes past uses.',
                ], $warnings),
                'available_actions' => [
                    [
                        'label' => 'List coupon assignments',
                        'method' => 'GET',
                        'path' => "/api/v1/coupons/{$coupon->getKey()}/assignments",
                    ],
                    [
                        'label' => 'Update coupon assignment',
                        'method' => 'PUT',
                        'path' => "/api/v1/coupons/{$coupon->getKey()}/assignments/{assignmentId}",
                    ],
                ],
            ]);
        }

        // single_use_per_user: public coupons already enforce one use per
        // customer via the prior-use check, so nothing needs to change.
        if ($isPublic) {
            return $this->apiResponse('Suggestion generated.', 200, true, [
                'current_state' => 'This coupon is public and already limited to one use per customer.',
                'recommended_action' => 'no_change_needed',
                'summary' => 'The coupon already supports the requested single-use behavior.',
                'steps' => [],
                'expected_result' => 'Each customer can use the coupon once.',
                'warnings' => $warnings,
                'available_actions' => [],
            ]);
        }

        if ($currentMaxUses <= 1) {
            return $this->apiResponse('Suggestion generated.', 200, true, [
                'current_state' => 'The current assignments already allow one use per customer.',
                'recommended_action' => 'no_change_needed',
                'summary' => 'The coupon already supports the requested single-use behavior.',
                'steps' => [],
                'expected_result' => 'Each assigned customer can use the coupon once.',
                'warnings' => $warnings,
                'available_actions' => [],
            ]);
        }

        // Assigned with max_uses > 1: lower the limit per assignment.
        // Removal is deliberately NOT recommended: deleting assignments
        // affects eligibility, claim state, usage history, and prior
        // notifications, and is never equivalent to a limit change.
        return $this->apiResponse('Suggestion generated.', 200, true, [
            'current_state' => "The current assignments allow up to {$currentMaxUses} uses per customer.",
            'recommended_action' => 'update_assignments',
            'summary' => 'Lower the per-customer usage limit on the existing assignments to one.',
            'steps' => [
                'Open the coupon customer assignments list.',
                'Select the assignments that should be limited to a single use.',
                'Set the maximum allowed uses to one for each selected customer.',
                'Save the changes for each assignment.',
                'Verify that each customer now has a one-use limit.',
            ],
            'expected_result' => 'The selected customers can use the coupon once.',
            'warnings' => array_merge([
                'Assignments can only be updated one at a time; there is no bulk update. No automatic change was made.',
                'The limit cannot be set below a customer already-recorded uses.',
                'Past usage history is preserved; customers who already used the coupon keep their history but cannot use it again.',
            ], $warnings),
            'available_actions' => [
                [
                    'label' => 'List coupon assignments',
                    'method' => 'GET',
                    'path' => "/api/v1/coupons/{$coupon->getKey()}/assignments",
                ],
                [
                    'label' => 'Update coupon assignment',
                    'method' => 'PUT',
                    'path' => "/api/v1/coupons/{$coupon->getKey()}/assignments/{assignmentId}",
                ],
            ],
        ]);
    }

    /**
     * Load the coupon targeting row without failing when the targeting
     * table is unavailable (mirrors the orchestrator's defensive read).
     */
    private function safeTargeting(Coupon $coupon)
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('coupon_targetings')) {
                return null;
            }

            return $coupon->targeting;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Claim/targeting compatibility notes appended to every suggestion.
     * Assignment usage limits never change targeting or claim rules, so any
     * interaction must be surfaced as a warning instead.
     *
     * @return list<string>
     */
    private function claimAndTargetingWarnings($targeting): array
    {
        $warnings = [];

        if ($targeting && (bool) ($targeting->require_claim ?? false)) {
            $warnings[] = 'Customers may need to claim the coupon before they can use it, depending on the coupon claim configuration. Changing usage limits does not change the claim requirement.';
        }

        if ($targeting && ($targeting->mode ?? null) === 'dynamic') {
            $warnings[] = 'This coupon uses dynamic targeting, which is evaluated independently of assignments. Per-customer assignment limits only take effect for targeting modes that honor assignments; changing the targeting mode is a separate admin action.';
        }

        return $warnings;
    }
}

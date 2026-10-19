<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Marvel\Database\Models\Coupon;
use Marvel\Traits\ApiResponse;

use Marvel\Enums\Permission;

class CouponConfigurationController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware(['auth:sanctum']);
        $this->middleware('permission:' . Permission::COUPONS_VALIDATE_CONFIG, ['only' => ['validateConfiguration']]);
        $this->middleware('permission:' . Permission::COUPONS_USAGE_INFO, ['only' => ['getUsageInfo']]);
        $this->middleware('permission:' . Permission::COUPONS_SUGGEST_FIX, ['only' => ['suggestFix']]);
    }

    private function authorizeAdmin(\Illuminate\Http\Request $request): void
    {
        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated.');
        }
        try {
            if (method_exists($user, 'hasPermissionTo') && (
                $user->hasPermissionTo('view-coupons')
                || $user->hasPermissionTo('update-coupon')
                || $user->hasPermissionTo('create-coupon')
                || $user->hasPermissionTo(Permission::COUPONS_VALIDATE_CONFIG)
                || $user->hasPermissionTo(Permission::COUPONS_USAGE_INFO)
                || $user->hasPermissionTo(Permission::COUPONS_SUGGEST_FIX)
            )) {
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
                    'message' => __('message.MESSAGE.COUPON_CONFIG_PUBLIC_SINGLE_USE'),
                ];
            }

            $limiterDisplay = $limiter ?? 'unlimited';
            $recommendations[] = [
                'title' => __('message.MESSAGE.COUPON_CONFIG_PUBLIC_BEHAVIOR_TITLE'),
                'description' => __('message.MESSAGE.COUPON_CONFIG_PUBLIC_BEHAVIOR_DESC', ['capacity' => $limiterDisplay]),
            ];
        }

        // Rule 2: Assigned coupons support multi-use via max_uses
        if ($couponType === 'assigned') {
            if ($maxUsesPerUser > 1) {
                $recommendations[] = [
                    'title' => __('message.MESSAGE.COUPON_CONFIG_MULTI_USE_TITLE'),
                    'description' => __('message.MESSAGE.COUPON_CONFIG_MULTI_USE_DESC', ['maxUses' => $maxUsesPerUser]),
                ];
            }

            if (!$limiter) {
                $warnings[] = [
                    'field' => 'limiter',
                    'message' => __('message.MESSAGE.COUPON_CONFIG_NO_LIMITER'),
                ];
            }
        }

        // Rule 3: Limiter check
        if ($limiter && $couponType === 'public') {
            if ($limiter > 1000) {
                $warnings[] = [
                    'field' => 'limiter',
                    'message' => __('message.MESSAGE.COUPON_CONFIG_HIGH_LIMIT', ['limiter' => $limiter]),
                ];
            }
        }

        return $this->apiResponse(__('message.MESSAGE.COUPON_CONFIG_VALIDATION_COMPLETED'), 200, empty($errors), [
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

        return $this->apiResponse(__('message.MESSAGE.COUPON_CONFIG_USAGE_RETRIEVED'), 200, true, [
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
            return $this->apiResponse(__('message.ERROR.COUPON_CONFIG_INVALID_BEHAVIOR'), 400, false, [
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
                return $this->apiResponse(__('message.MESSAGE.COUPON_CONFIG_SUGGESTION'), 200, true, [
                    'current_state' => __('message.MESSAGE.COUPON_GUIDE_PUBLIC_NO_ASSIGN'),
                    'recommended_action' => 'convert_to_assigned',
                    'summary' => __('message.MESSAGE.COUPON_GUIDE_SUMMARY_INDIVIDUAL_LIMIT'),
                    'steps' => [
                        __('message.MESSAGE.COUPON_GUIDE_STEP_SELECT_CUSTOMERS'),
                        __('message.MESSAGE.COUPON_GUIDE_STEP_CREATE_ASSIGNMENT'),
                        __('message.MESSAGE.COUPON_GUIDE_STEP_SET_MAX_USES'),
                        __('message.MESSAGE.COUPON_GUIDE_STEP_VERIFY_ACTIVE'),
                        __('message.MESSAGE.COUPON_GUIDE_STEP_INDIVIDUAL_LIMIT'),
                    ],
                    'expected_result' => __('message.MESSAGE.COUPON_GUIDE_RESULT_INDEPENDENT_LIMIT'),
                    'warnings' => array_merge([
                        __('message.MESSAGE.COUPON_GUIDE_WARN_CONVERTS'),
                        __('message.MESSAGE.COUPON_GUIDE_WARN_HISTORY_KEPT'),
                    ], $warnings),
                    'available_actions' => [
                        [
                            'label' => __('message.MESSAGE.COUPON_GUIDE_LABEL_CREATE'),
                            'method' => 'POST',
                            'path' => "/api/v1/coupons/{$coupon->getKey()}/assignments",
                        ],
                        [
                            'label' => __('message.MESSAGE.COUPON_GUIDE_LABEL_LIST'),
                            'method' => 'GET',
                            'path' => "/api/v1/coupons/{$coupon->getKey()}/assignments",
                        ],
                    ],
                ]);
            }

            if ($currentMaxUses > 1) {
                return $this->apiResponse(__('message.MESSAGE.COUPON_CONFIG_SUGGESTION'), 200, true, [
                    'current_state' => __('message.MESSAGE.COUPON_GUIDE_ALREADY_MULTI', ['maxUses' => $currentMaxUses]),
                    'recommended_action' => 'no_change_needed',
                    'summary' => __('message.MESSAGE.COUPON_GUIDE_SUMMARY_ALREADY_OK'),
                    'steps' => [],
                    'expected_result' => __('message.MESSAGE.COUPON_GUIDE_RESULT_CONFIGURED_LIMIT'),
                    'warnings' => $warnings,
                    'available_actions' => [],
                ]);
            }

            return $this->apiResponse(__('message.MESSAGE.COUPON_CONFIG_SUGGESTION'), 200, true, [
                'current_state' => __('message.MESSAGE.COUPON_GUIDE_SINGLE_ASSIGN'),
                'recommended_action' => 'update_assignments',
                'summary' => __('message.MESSAGE.COUPON_GUIDE_SUMMARY_RAISE_LIMIT'),
                'steps' => [
                    __('message.MESSAGE.COUPON_GUIDE_STEP_OPEN_LIST'),
                    __('message.MESSAGE.COUPON_GUIDE_STEP_SELECT_MULTI'),
                    __('message.MESSAGE.COUPON_GUIDE_STEP_INCREASE_LIMIT'),
                    __('message.MESSAGE.COUPON_GUIDE_STEP_SAVE_CHANGES'),
                    __('message.MESSAGE.COUPON_GUIDE_STEP_VERIFY_LIMITS'),
                ],
                'expected_result' => __('message.MESSAGE.COUPON_GUIDE_RESULT_MULTI_LIMIT'),
                'warnings' => array_merge([
                    __('message.MESSAGE.COUPON_GUIDE_WARN_ONE_AT_A_TIME'),
                    __('message.MESSAGE.COUPON_GUIDE_WARN_NO_REVOKE'),
                ], $warnings),
                'available_actions' => [
                    [
                        'label' => __('message.MESSAGE.COUPON_GUIDE_LABEL_LIST'),
                        'method' => 'GET',
                        'path' => "/api/v1/coupons/{$coupon->getKey()}/assignments",
                    ],
                    [
                        'label' => __('message.MESSAGE.COUPON_GUIDE_LABEL_UPDATE'),
                        'method' => 'PUT',
                        'path' => "/api/v1/coupons/{$coupon->getKey()}/assignments/{assignmentId}",
                    ],
                ],
            ]);
        }

        // single_use_per_user: public coupons already enforce one use per
        // customer via the prior-use check, so nothing needs to change.
        if ($isPublic) {
            return $this->apiResponse(__('message.MESSAGE.COUPON_CONFIG_SUGGESTION'), 200, true, [
                'current_state' => __('message.MESSAGE.COUPON_GUIDE_PUBLIC_SINGLE'),
                'recommended_action' => 'no_change_needed',
                'summary' => __('message.MESSAGE.COUPON_GUIDE_SUMMARY_ALREADY_SINGLE'),
                'steps' => [],
                'expected_result' => __('message.MESSAGE.COUPON_GUIDE_RESULT_ONCE'),
                'warnings' => $warnings,
                'available_actions' => [],
            ]);
        }

        if ($currentMaxUses <= 1) {
            return $this->apiResponse(__('message.MESSAGE.COUPON_CONFIG_SUGGESTION'), 200, true, [
                'current_state' => __('message.MESSAGE.COUPON_GUIDE_ALREADY_SINGLE'),
                'recommended_action' => 'no_change_needed',
                'summary' => __('message.MESSAGE.COUPON_GUIDE_SUMMARY_ALREADY_SINGLE'),
                'steps' => [],
                'expected_result' => __('message.MESSAGE.COUPON_GUIDE_RESULT_ASSIGNED_ONCE'),
                'warnings' => $warnings,
                'available_actions' => [],
            ]);
        }

        // Assigned with max_uses > 1: lower the limit per assignment.
        // Removal is deliberately NOT recommended: deleting assignments
        // affects eligibility, claim state, usage history, and prior
        // notifications, and is never equivalent to a limit change.
        return $this->apiResponse(__('message.MESSAGE.COUPON_CONFIG_SUGGESTION'), 200, true, [
            'current_state' => __('message.MESSAGE.COUPON_GUIDE_MULTI_ASSIGN', ['maxUses' => $currentMaxUses]),
            'recommended_action' => 'update_assignments',
            'summary' => __('message.MESSAGE.COUPON_GUIDE_SUMMARY_LOWER_LIMIT'),
            'steps' => [
                __('message.MESSAGE.COUPON_GUIDE_STEP_OPEN_LIST'),
                __('message.MESSAGE.COUPON_GUIDE_STEP_SELECT_SINGLE'),
                __('message.MESSAGE.COUPON_GUIDE_STEP_SET_ONE'),
                __('message.MESSAGE.COUPON_GUIDE_STEP_SAVE_CHANGES'),
                __('message.MESSAGE.COUPON_GUIDE_STEP_VERIFY_ONE'),
            ],
            'expected_result' => __('message.MESSAGE.COUPON_GUIDE_RESULT_SELECTED_ONCE'),
            'warnings' => array_merge([
                __('message.MESSAGE.COUPON_GUIDE_WARN_ONE_AT_A_TIME'),
                __('message.MESSAGE.COUPON_GUIDE_WARN_MIN_LIMIT'),
                __('message.MESSAGE.COUPON_GUIDE_WARN_HISTORY_PRESERVED'),
            ], $warnings),
            'available_actions' => [
                [
                    'label' => __('message.MESSAGE.COUPON_GUIDE_LABEL_LIST'),
                    'method' => 'GET',
                    'path' => "/api/v1/coupons/{$coupon->getKey()}/assignments",
                ],
                [
                    'label' => __('message.MESSAGE.COUPON_GUIDE_LABEL_UPDATE'),
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
            $warnings[] = __('message.MESSAGE.COUPON_GUIDE_WARN_CLAIM');
        }

        if ($targeting && ($targeting->mode ?? null) === 'dynamic') {
            $warnings[] = __('message.MESSAGE.COUPON_GUIDE_WARN_DYNAMIC');
        }

        return $warnings;
    }
}

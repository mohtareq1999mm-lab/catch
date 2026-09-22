<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Coupon\CouponRuleMetadata;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;

/**
 * Read-only coupon Rule Catalog metadata for the Admin targeting builder.
 *
 * Static data only (no customer reads). Rule identifiers and constraints
 * are derived from EligibilityRuleType + RuleTreeValidator — this
 * controller holds no rule definitions itself.
 */
class CouponRulesController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware(['auth:sanctum']);
    }

    /**
     * GET /api/v1/coupons/rules (canonical)
     * GET /api/v1/admin/coupons/rules (legacy alias)
     */
    public function show(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        try {
            if (method_exists($user, 'hasPermissionTo') && ($user->hasPermissionTo('view-coupons') || $user->hasPermissionTo('update-coupon') || $user->hasPermissionTo('create-coupon'))) {
                // authorized
            } elseif (method_exists($user, 'can') && ($user->can('view-coupons') || $user->can('update-coupon'))) {
                // authorized
            } elseif (($user->type ?? null) === 'admin' || ($user->role ?? null) === 'admin') {
                if (method_exists($user, 'hasPermissionTo')) {
                    abort(403, 'Forbidden. Missing required permission: view-coupons.');
                }
            } else {
                abort(403, 'Forbidden. Missing required permission: view-coupons.');
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Coupon rules auth check failed', ['error' => $e->getMessage()]);
            abort(403, 'Forbidden. Missing required permission: view-coupons.');
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'rules' => CouponRuleMetadata::all(),
            'rule_tree' => CouponRuleMetadata::grammar(),
        ]);
    }
}

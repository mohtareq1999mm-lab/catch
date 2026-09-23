<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coupon\UpsertTargetingRequest;
use App\Http\Resources\Coupon\CouponTargetingResource;
use App\Services\Coupon\RuleTreeValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Traits\ApiResponse;

class CouponTargetingController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware(['auth:sanctum']);
    }

    private function authorizeAdmin(Request $request): void
    {
        $this->authorizeRead($request);
    }

    private function authorizeRead(Request $request): void
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
            \Illuminate\Support\Facades\Log::warning('Coupon targeting auth check failed', ['error' => $e->getMessage()]);
        }
        abort(403, 'Forbidden. Missing required permission: view-coupons.');
    }

    /**
     * BLOCKER fix: writes require update/create, not view-only.
     * Mirrors Marvel CouponController permission split (VIEW vs CREATE/UPDATE).
     */
    private function authorizeWrite(Request $request): void
    {
        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated.');
        }
        try {
            if (method_exists($user, 'hasPermissionTo') && ($user->hasPermissionTo('update-coupon') || $user->hasPermissionTo('create-coupon'))) {
                return;
            }
            if (method_exists($user, 'can') && $user->can('update-coupon')) {
                return;
            }
            // type=admin without explicit permission is NOT sufficient for writes.
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Coupon targeting write auth check failed', ['error' => $e->getMessage()]);
        }
        abort(403, 'Forbidden. Missing required permission: update-coupon.');
    }

    /**
     * GET /api/v1/admin/coupons/{id}/targeting
     */
    public function show(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        $coupon = Coupon::findOrFail($id);
        $targeting = $coupon->targeting;

        if (!$targeting) {
            return $this->apiResponse(COUPON_NO_TARGETING, 404, false);
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CouponTargetingResource::make($targeting));
    }

    /**
     * PUT /api/v1/admin/coupons/{id}/targeting
     * Body: mode, require_claim, max_claims?, claim_ttl_hours?, rule_tree?
     */
    public function upsert(UpsertTargetingRequest $request, int $id)
    {
        $this->authorizeWrite($request);
        $coupon = Coupon::findOrFail($id);
        $data = $request->validated();

        // Fail-closed rule_tree validation (admin input never creates an open gate).
        $check = RuleTreeValidator::validate($data['rule_tree'] ?? null);
        if (!$check['valid']) {
            return $this->apiResponse(COULD_NOT_UPDATE_THE_RESOURCE, 422, false, [
                'errors' => $check['errors'],
            ]);
        }

        // Dynamic-family modes with null rule_tree mean "no rules" (eligible).
        // Assignment mode ignores rule_tree at runtime; store as given.
        $targeting = DB::transaction(function () use ($coupon, $data) {
            return CouponTargeting::updateOrCreate(
                ['coupon_id' => $coupon->getKey()],
                [
                    'mode' => $data['mode'],
                    'require_claim' => (bool) $data['require_claim'],
                    'max_claims' => $data['max_claims'] ?? null,
                    'claim_ttl_hours' => $data['claim_ttl_hours'] ?? null,
                    'rule_tree' => $data['rule_tree'] ?? null,
                ]
            );
        });

        // Distribution: a new targeting version re-opens evaluation.
        event(new \App\Events\Coupons\CouponTargetingChanged($coupon->fresh()));

        // Discovery caches key visibility, claim requirement, and codes off
        // this row — retire them alongside the distribution fan-out.
        \App\Services\Coupon\Discovery\CouponDiscoveryCache::invalidate();

        return $this->apiResponse(UPDATED_COUPON_SUCCESSFULLY, 200, true, CouponTargetingResource::make($targeting->fresh()));
    }

    /**
     * DELETE /api/v1/admin/coupons/{id}/targeting
     * Removes targeting → coupon becomes always-eligible (backward compat).
     */
    public function destroy(Request $request, int $id)
    {
        $this->authorizeWrite($request);
        $coupon = Coupon::findOrFail($id);
        $targeting = $coupon->targeting;

        if (!$targeting) {
            return $this->apiResponse(COUPON_NO_TARGETING, 404, false);
        }

        $targeting->delete();

        // Distribution: removing targeting returns the coupon to
        // always-eligible; in-flight targeted runs stop harmlessly.
        event(new \App\Events\Coupons\CouponTargetingChanged($coupon->fresh()));

        // The coupon flips back to public visibility: retire discovery caches.
        \App\Services\Coupon\Discovery\CouponDiscoveryCache::invalidate();

        return $this->apiResponse(DELETED_COUPON_SUCCESSFULLY, 200, true);
    }
}

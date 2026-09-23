<?php

namespace App\Http\Controllers\Api\General;

use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Http\Resources\Coupons\CouponResource;
use App\Services\General\CouponService;
use App\Traits\HasCache;
use Marvel\Traits\ApiResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    use ApiResponse, HasCache;
    protected $couponService;
    public function __construct(CouponService $couponService)
    {
        $this->couponService = $couponService;
    }

    public function index(Request $request)
    {
        $coupons = $this->couponService->getCoupons($request);
        $couponsCache = $this->remember(FrontendResource::COUPONS->value, md5($request->fullUrl()), $coupons);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CouponResource::collection($couponsCache));
    }

    public function applyCoupon(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'max:191'],
        ]);

        // AREA_IN saved-address remediation: coupon area eligibility is
        // derived from the authenticated user's saved addresses. No
        // customer-supplied governorate is accepted or needed here.
        $code = $request->get('code');
        $result = $this->couponService->addCouponToCart($code);

        if ($result === null) {
            return $this->apiResponse(INVALID_COUPON_CODE_OR_COUPON_CANNOT_BE_APPLIED_OR_COUPON_USAGE_LIMIT_REACHED, 400, false, ['reason' => 'no_cart', 'code' => 'COUPON_NO_CART']);
        }

        // P2-4: machine-readable rejection code; envelope unchanged.
        if (isset($result['invalid']) && $result['invalid']) {
            $reason = (string) ($result['reason'] ?? 'not_eligible');

            return $this->apiResponse(INVALID_COUPON_CODE_OR_COUPON_CANNOT_BE_APPLIED_OR_COUPON_USAGE_LIMIT_REACHED, 400, false, ['reason' => $reason, 'code' => 'COUPON_'.strtoupper($reason)]);
        }

        if (isset($result['already_applied']) && $result['already_applied']) {
            return $this->apiResponse(COUPON_ALREADY_APPLIED, 200, true, $result);
        }

        return $this->apiResponse(COUPON_APPLIED_SUCCESSFULLY, 200, true, $result);
    }

    /**
     * My Coupons: assignments + claims for the authenticated user.
     *
     * FINAL BUSINESS CONTRACT Sec 3 (View → Claim → My Coupons → Apply)
     * and Sec 6 (assignment discoverable via relationship/API).
     * Coupon codes are exposed ONLY to their owner (needed for Apply);
     * the public listing (CouponResource) never exposes codes.
     *
     * @OA\Get(
     *     path="/api/v1/general/coupons/mine",
     *     tags={"Coupons"},
     *     summary="My coupons (assigned + claimed)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="User coupons"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function myCoupons(Request $request)
    {
        $userId = $request->user()->getKey();

        $assignments = \Marvel\Database\Models\CouponAssignment::query()
            ->where('user_id', $userId)
            ->with('coupon')
            ->orderByDesc('assigned_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'coupon_id' => $a->coupon_id,
                'code' => $a->coupon?->code,
                'max_uses' => $a->max_uses,
                'used' => $a->used,
                'remaining' => max(0, (int) $a->max_uses - (int) $a->used),
                'expired' => $a->expires_at !== null && $a->expires_at->isPast(),
                'expires_at' => $a->expires_at?->toIso8601String(),
                'assigned_at' => $a->assigned_at?->toIso8601String(),
            ]);

        $claims = \Marvel\Database\Models\CouponClaim::query()
            ->where('user_id', $userId)
            ->with('coupon')
            ->orderByDesc('claimed_at')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'coupon_id' => $c->coupon_id,
                'code' => $c->coupon?->code,
                'status' => $c->status instanceof \BackedEnum ? $c->status->value : (string) $c->status,
                'claimed_at' => $c->claimed_at?->toIso8601String(),
                'expires_at' => $c->expires_at?->toIso8601String(),
                'redeemed_at' => $c->redeemed_at?->toIso8601String(),
            ]);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'assignments' => $assignments,
            'claims' => $claims,
        ]);
    }

    /**
     * Claim a coupon (targeting system).
     *
     * @OA\Post(
     *     path="/api/v1/general/coupons/{id}/claim",
     *     tags={"Coupons"},
     *     summary="Claim a coupon",
     *     description="Claim a coupon with targeting/eligibility rules",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=201, description="Coupon claimed successfully"),
     *     @OA\Response(response=400, description="Validation error"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=409, description="Already claimed or not eligible")
     * )
     */
    public function claim(\App\Http\Requests\Coupon\ClaimCouponRequest $request, int $id)
    {
        try {
            $coupon = \Marvel\Database\Models\Coupon::findOrFail($id);
            $user = $request->user();

            $claimService = app(\App\Services\Coupon\CouponClaimService::class);
            $claim = $claimService->claim($coupon, $user);
            $claim->loadMissing('coupon:id,code');

            return $this->apiResponse(
                COUPON_CLAIMED_SUCCESSFULLY,
                201,
                true,
                \App\Http\Resources\Coupon\CouponClaimResource::make($claim)
            );
        } catch (\App\Exceptions\CouponClaimException $e) {
            // F-11: customer response carries only reason code; internal
            // diagnostics (failed_rules, coupon/user ids) stay in logs.
            \Illuminate\Support\Facades\Log::info('Coupon claim rejected', [
                'reason' => $e->reason,
                'context' => $e->context,
                'coupon_id' => $id,
                'user_id' => $request->user()?->getKey(),
            ]);

            return $this->apiResponse(
                $this->mapClaimExceptionMessage($e),
                409,
                false,
                ['reason' => $e->reason]
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->apiResponse(COUPON_NOT_FOUND, 404, false);
        } catch (\Throwable $e) {
            report($e);
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    /**
     * Available coupons: personalized discovery for the authenticated user.
     *
     * Advisory only — Engine-eligible, valid, targeted coupons with
     * owner-safe shells (never codes, rules, or counters). Claim/apply/
     * checkout revalidate authoritatively.
     *
     * @OA\Get(
     *     path="/api/v1/general/coupons/available",
     *     tags={"Coupons"},
     *     summary="Coupons available to me",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Available coupons"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function available(Request $request)
    {
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $service = app(\App\Services\Coupon\Distribution\Discovery\AvailableCouponsService::class);
        $result = $service->forUser(
            $request->user(),
            (int) $request->query('page', 1),
            (int) $request->query('limit', config('coupon-distribution.available_default_limit', 15)),
        );

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    private function mapClaimExceptionMessage(\App\Exceptions\CouponClaimException $e): string
    {
        return match ($e->reason) {
            \App\Exceptions\CouponClaimException::REASON_ALREADY_CLAIMED => COUPON_ALREADY_CLAIMED,
            \App\Exceptions\CouponClaimException::REASON_NOT_ELIGIBLE => COUPON_NOT_ELIGIBLE,
            \App\Exceptions\CouponClaimException::REASON_CLAIM_NOT_REQUIRED => COUPON_CLAIM_NOT_REQUIRED,
            \App\Exceptions\CouponClaimException::REASON_NO_TARGETING => COUPON_NO_TARGETING,
            \App\Exceptions\CouponClaimException::REASON_MAX_CLAIMS_REACHED => COUPON_MAX_CLAIMS_REACHED,
            default => SOMETHING_WENT_WRONG,
        };
    }
}

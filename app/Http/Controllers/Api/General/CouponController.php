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

        $code = $request->get('code');
        $result = $this->couponService->addCouponToCart($code);

        if ($result === null) {
            return $this->apiResponse(INVALID_COUPON_CODE_OR_COUPON_CANNOT_BE_APPLIED_OR_COUPON_USAGE_LIMIT_REACHED, 400, false);
        }

        if (isset($result['already_applied']) && $result['already_applied']) {
            return $this->apiResponse(COUPON_ALREADY_APPLIED, 200, true, $result);
        }

        return $this->apiResponse(COUPON_APPLIED_SUCCESSFULLY, 200, true, $result);
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

            return $this->apiResponse(
                COUPON_CLAIMED_SUCCESSFULLY,
                201,
                true,
                \App\Http\Resources\Coupon\CouponClaimResource::make($claim)
            );
        } catch (\App\Exceptions\CouponClaimException $e) {
            return $this->apiResponse(
                $this->mapClaimExceptionMessage($e),
                409,
                false,
                ['reason' => $e->reason, 'context' => $e->context]
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->apiResponse(COUPON_NOT_FOUND, 404, false);
        } catch (\Throwable $e) {
            report($e);
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
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

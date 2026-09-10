<?php

namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\CouponRequest;
use Marvel\Http\Requests\UpdateCouponRequest;
use Marvel\Database\Repositories\CouponRepository;
use Prettus\Validator\Exceptions\ValidatorException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Marvel\Database\Models\Coupon;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Http\Resources\CouponResource;
use Marvel\Traits\ApiResponse;
use Svg\Tag\Rect;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * @OA\Schema(
 *     schema="Coupon",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="code", type="string", example="SAVE50"),
 *     @OA\Property(property="description", type="string", example="Get 50% off your first order"),
 *     @OA\Property(property="image", type="object"),
 *     @OA\Property(property="type", type="string", example="percentage"),
 *     @OA\Property(property="amount", type="number", format="float", example=50.00),
 *     @OA\Property(property="minimum_cart_amount", type="number", format="float", example=100.00),
 *     @OA\Property(property="active_from", type="string", format="date-time"),
 *     @OA\Property(property="expire_at", type="string", format="date-time"),
 *     @OA\Property(property="is_approve", type="boolean", example=true),
 *     @OA\Property(property="language", type="string", example="en"),
 *     @OA\Property(property="shop_id", type="integer")
 * )
 */
class CouponController extends CoreController
{
    use ApiResponse , HasCache;
    public $repository;

    public function __construct(CouponRepository $repository)
    {
        $this->repository = $repository;
        $this->middleware("permission:" . Permission::VIEW_COUPONS, ["only" => ["index", "show"]]);
        $this->middleware("permission:" . Permission::CREATE_COUPON, ["only" => ["store"]]);
        $this->middleware("permission:" . Permission::UPDATE_COUPON, ["only" => ["update"]]);
        $this->middleware("permission:" . Permission::DELETE_COUPON, ["only" => ["destroy"]]);
        $this->middleware("role:" . Role::SUPER_ADMIN, ["only" => ["approveCoupon", "disApproveCoupon"]]);
    }

    /**
     * @OA\Get(
     *     path="/coupons",
     *     operationId="getCoupons",
     *     tags={"Coupons"},
     *     summary="List all coupons",
     *     description="Retrieve a paginated list of coupons with optional shop and language filtering.",
     *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", default=15)),
     *     @OA\Parameter(name="shop_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="language", in="query", @OA\Schema(type="string", default="en")),
     *     @OA\Response(
     *         response=200,
     *         description="List of coupons",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Coupon")),
     *             @OA\Property(property="current_page", type="integer"),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $limit = $request->limit ?? 15;
        $query = $this->fetchCoupons($request);
        $coupons = $query->paginate($limit)->withQueryString();
        $couponData = CouponResource::collection($coupons)->response()->getData(true);
        $couponCache = $this->remember(FrontendResource::COUPONS->value,md5($request->fullUrl()),$couponData);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $couponCache['data'] ?? [],
            "page" => $couponCache['meta']['current_page'] ?? 0,
            "current_page" => $couponCache['meta']['current_page'] ?? 0,
            "from" => $couponCache['meta']['from'] ?? 0,
            "to" => $couponCache['meta']['to'] ?? 0,
            "last_page" => $couponCache['meta']['last_page'] ?? 0,
            "path" => $couponCache['meta']['path'] ?? "",
            "per_page" => $couponCache['meta']['per_page'] ?? 0,
            "total" => $couponCache['meta']['total'] ?? 0,
            "next_page_url" => $couponCache['links']['next'] ?? "",
            "prev_page_url" => $couponCache['links']['prev'] ?? "",
            "last_page_url" => $couponCache['links']['last'] ?? "",
            "first_page_url" => $couponCache['links']['first'] ?? "",
        ]);
    }
    public function fetchCoupons(Request $request)
    {
        $active = $request->active ?? null;
        $Inactive = $request->inactive ?? null;
        $search = $request->search ?? null;
        $order = $request->order;
        $sortedBy = $request->sortedBy ?? 'asc';
        $query = $this->repository->modelQuery();
        if ($active) {
            $query = $query->valid();
        }
        if ($Inactive) {
            $query = $query->invalid();
        }
        if ($search) {
            $query = $query->search('name', $search, app()->getLocale())
                ->orWhere('code', 'like', "%$search%");
        }
        if ($order && in_array($order, ['id', 'code', 'name', 'discount', 'discount_type', 'start_date', 'end_date', 'limiter', 'used', 'status', 'created_at', 'updated_at'])) {
            $query = $query->orderBy($order, $sortedBy === 'desc' ? 'desc' : 'asc');
        }
        return $query;
    }
    public function store(CouponRequest $request)
    {
        try {
            $coupon =  $this->repository->storeCoupon($request);
            $this->forget(FrontendResource::COUPONS->value);
            return $this->apiResponse(CREATED_COUPON_SUCCESSFULLY, 201, true, CouponResource::make($coupon));
        } catch (MarvelException $e) {
            return $this->apiResponse(COULD_NOT_CREATE_THE_RESOURCE, 400, false);
        }
    }


    public function show(Request $request, $id)
    {
        try {

            $coupon =  $this->repository->where('id', $id)->orWhere('code', $id)->firstOrFail();
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CouponResource::make($coupon));
        } catch (Throwable $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
    }

    // public function verify(Request $request)
    // {
    //     $request->validate([
    //         'code' => 'required|string',
    //         'sub_total' => 'required|numeric',
    //     ]);
    //     try {
    //         return $this->repository->verifyCoupon($request);
    //     } catch (MarvelException $e) {
    //         throw new MarvelException(NOT_FOUND);
    //     }
    // }


    public function update(UpdateCouponRequest $request, $id)
    {
        try {
            $coupon = $this->repository->updateCoupon($id, $request);
            $this->forget(FrontendResource::COUPONS->value);
            return $this->apiResponse(UPDATED_COUPON_SUCCESSFULLY, 200, true, CouponResource::make($coupon));
        } catch (MarvelException $th) {
            return $this->apiResponse(COULD_NOT_UPDATE_THE_RESOURCE, 400, false);
        }
    }


    public function destroy($id)
    {
        try {
            $this->repository->findOrFail($id)->delete();
            $this->forget(FrontendResource::COUPONS->value);
            return $this->apiResponse(DELETED_COUPON_SUCCESSFULLY, 200, true);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }


    public function addCouponToCart(Request $request)
    {
        try {
            $request->validate([
                'code' => 'required|string|exists:coupons,code',
            ]);
            $coupon = $this->repository->addCouponToCart($request->code);
            return $this->apiResponse(COUPON_ADDED_TO_CART_SUCCESSFULLY, 200, true);
        } catch (MarvelException $e) {
            return $this->apiResponse(COULD_NOT_ADD_COUPON_TO_CART, 400, false);
        }
    }


    public function approveCoupon(Request $request)
    {
        if (!auth()->user()?->hasRole(Role::SUPER_ADMIN)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        try {
            $coupon = $this->repository->findOrFail($request->id);
            $coupon->update(['is_approve' => true]);
            return $this->apiResponse(UPDATED_COUPON_SUCCESSFULLY, 200, true, CouponResource::make($coupon));
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }


    public function disApproveCoupon(Request $request)
    {
        if (!auth()->user()?->hasRole(Role::SUPER_ADMIN)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        try {
            $coupon = $this->repository->findOrFail($request->id);
            $coupon->is_approve = false;
            $coupon->save();
            return $this->apiResponse(UPDATED_COUPON_SUCCESSFULLY, 200, true, CouponResource::make($coupon));
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * Claim a coupon for the authenticated user.
     *
     * @OA\Post(
     *     path="/coupons/{id}/claim",
     *     operationId="claimCoupon",
     *     tags={"Coupons"},
     *     summary="Claim a coupon",
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
            $coupon = Coupon::findOrFail($id);
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
        } catch (ModelNotFoundException $e) {
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

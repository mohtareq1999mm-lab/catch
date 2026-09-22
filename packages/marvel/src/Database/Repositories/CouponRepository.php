<?php


namespace Marvel\Database\Repositories;

use App\Services\Coupon\CouponOrchestrator;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Coupon;
use Marvel\Traits\MediaManager;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Marvel\Exceptions\MarvelBadRequestException;

class CouponRepository extends BaseRepository
{
    use MediaManager;

    /**
     * @var array
     */
    protected $fieldSearchable = [
        'code' => 'like',
        'name' => 'like',

    ];

    protected $dataArray = [
        "name",
        'slug',
        'discount',
        'discount_type',
        'border_color',
        'borderless',
        'start_date',
        'end_date',
        'limiter',
        'status',
        "max_discount_amount",
    ];

    public function getDataArray(): array
    {
        return $this->dataArray;
    }

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }
    /**
     * Configure the Model
     **/
    public function model()
    {
        return Coupon::class;
    }
    public function modelQuery()
    {
        return Coupon::query();
    }

    /**
     * storeCoupon
     *
     * @param  mixed $request
     * @return mixed
     */
    public function storeCoupon(Request $request)
    {
        try {
            DB::beginTransaction();
            // CP-11: explicit whitelist. Generic input MUST NOT reach
            // system-managed fields (`used`, `code`, counters). Only the
            // business fields in $dataArray are persisted; the redeemable
            // code is always server-generated (Coupon::creating) and the
            // slug is sanitized/auto-generated server-side.
            $coupon = $this->create($request->only($this->dataArray));

            if ($request->hasFile('image-desktop')) {
                if (!$this->uploadSingleImage($request, 'image-desktop', $coupon, 'coupons-desktop', 'coupons')) {
                    throw new MarvelBadRequestException(COULD_NOT_CREATE_THE_RESOURCE);
                }
            }
            if ($request->hasFile('image-mobile')) {
                if (!$this->uploadSingleImage($request, 'image-mobile', $coupon, 'coupons-mobile', 'coupons')) {
                    throw new MarvelBadRequestException(COULD_NOT_CREATE_THE_RESOURCE);
                }
            }

            DB::commit();

            return $coupon;
        } catch (Exception $th) {
            DB::rollBack();
            // S8: keep the generic client-facing error (no oracle) but
            // report the real cause for operators.
            report($th);
            throw new MarvelBadRequestException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }
    public function updateCoupon($id, Request $request)
    {
        try {
            DB::beginTransaction();
            $coupon = $this->find($id);
            if (!$coupon) {
                throw new MarvelBadRequestException(COULD_NOT_UPDATE_THE_RESOURCE);
            }
            // CP-11: explicit whitelist (see storeCoupon). `used` and other
            // system-managed fields are never writable through generic input.
            $data = $request->only($this->dataArray);

            $coupon->update($data);

            if ($request->hasFile('image-desktop')) {
                if (!$this->updateSingleImage($request, 'image-desktop', $coupon, 'coupons-desktop', 'coupons')) {
                    throw new MarvelBadRequestException(COULD_NOT_UPDATE_THE_RESOURCE);
                }
            }
            if ($request->hasFile('image-mobile')) {
                if (!$this->updateSingleImage($request, 'image-mobile', $coupon, 'coupons-mobile', 'coupons')) {
                    throw new MarvelBadRequestException(COULD_NOT_UPDATE_THE_RESOURCE);
                }
            }
            DB::commit();

            return $coupon;
        } catch (Exception $th) {
            DB::rollBack();
            // S8: report the real cause; client still gets a generic error.
            report($th);
            throw new MarvelBadRequestException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    public function addCouponToCart($code)
    {
        $user = auth()->user();
        if (!$user) {
            throw new MarvelBadRequestException(NOT_AUTHORIZED);
        }
        $cart = $user->cart;

        // CP-06: dead storefront path (no route). Kept consistent with the
        // canonical flow: full Orchestrator (claim + assignment branches).
        $validation = CouponOrchestrator::validateByCode($code, $user, $cart?->items);
        if (!$validation['valid']) {
            throw new MarvelBadRequestException(COULD_NOT_ADD_COUPON_TO_CART_NOT_VALID);
        }

        $coupon = $validation['coupon'];

        if (!$cart || !$cart->items()->exists()) {
            throw new MarvelBadRequestException(COULD_NOT_ADD_COUPON_TO_EMPTY_CART);
        }

        // S1: canonical compare (see App CouponService).
        if (\App\Support\CouponCode::normalize($cart->coupon) === \App\Support\CouponCode::normalize($code)) {
            throw new MarvelBadRequestException(COULD_NOT_ADD_COUPON_TO_CART_YOU_HAVE_ALREADY_APPLIED_A_COUPON);
        }

        return $cart->update(['coupon' => $coupon->code]);
    }
}

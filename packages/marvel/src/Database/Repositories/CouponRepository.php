<?php


namespace Marvel\Database\Repositories;

use App\Services\Coupon\CouponValidator;
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
            $coupon = $this->create($request->except('image-desktop', 'image-mobile'));

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
            $data = $request->except('image-desktop', 'image-mobile');

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

        $validation = CouponValidator::validateByCode($code, $user, $cart?->items);
        if (!$validation['valid']) {
            throw new MarvelBadRequestException(COULD_NOT_ADD_COUPON_TO_CART_NOT_VALID);
        }

        $coupon = $validation['coupon'];

        if (!$cart || !$cart->items()->exists()) {
            throw new MarvelBadRequestException(COULD_NOT_ADD_COUPON_TO_EMPTY_CART);
        }

        if ($cart->coupon === $code) {
            throw new MarvelBadRequestException(COULD_NOT_ADD_COUPON_TO_CART_YOU_HAVE_ALREADY_APPLIED_A_COUPON);
        }

        return $cart->update(['coupon' => $coupon->code]);
    }
}

<?php

namespace App\Services\General;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Coupon;
use App\Services\Coupon\CouponOrchestrator;
use App\Services\Coupon\CouponCalculator;

class CouponService
{
    public function getCoupons($request)
    {
        $name = $request->get("search", false);
        $limit = min(100, max(1, (int) $request->get('limit', 10)));
        $start_date = $request->query('start_date');
        $end_date   = $request->query('end_date');
        $couponsId = $request->query('couponsId');
        $order = $request->query('order', 'desc');
        $coupons = Coupon::valid()->when($name, function ($query) use ($name) {
            $query->search('name', $name, app()->getLocale());
        })->when($start_date, function ($query) use ($start_date) {
                $query->where('created_at', '>=', $start_date);
            })
            ->when($end_date, function ($query) use ($end_date) {
                $query->where('created_at', '<=', $end_date);
            });

        if (!empty($couponsId)) {
            $ids = is_array($couponsId) ? $couponsId : explode(',', $couponsId);
            $ids = array_filter($ids, 'is_numeric');
            if (!empty($ids)) {
                $coupons->whereIn('id', $ids);
            }
        }

        return $coupons->orderBy('id', $order)->limit($limit)->get();
    }

    public function calcPrice(Coupon $coupon, $price)
    {
        $result = CouponCalculator::calculate($coupon, (float) $price);
        return $result['finalPrice'];
    }

    public function calcPriceByCode(string $code, $price): ?float
    {
        $coupon = Coupon::where('code', $code)->first();

        if (!$coupon) {
            return null;
        }

        $result = CouponCalculator::calculate($coupon, (float) $price);
        return $result['finalPrice'];
    }

    public function findByCode(string $code): ?Coupon
    {
        return Coupon::where('code', $code)->first();
    }

    public function addCouponToCart($code)
    {
        return DB::transaction(function () use ($code) {
            $user = auth()->user();

            if (!$user || !$user->cart) {
                return null;
            }

            $cart = $user->cart;

            if ($cart->coupon === $code) {
                return ['already_applied' => true];
            }

            $validation = CouponOrchestrator::validateByCode($code, $user, $cart->items);

            if (!$validation['valid']) {
                return null;
            }

            $coupon = $validation['coupon'];

            $result = $this->updateCartTotalPrice($cart, $coupon);
            return $result;
        });
    }

    private function updateCartTotalPrice($cart, $coupon)
    {
        $couponTotal = CouponCalculator::calculate($coupon, (float) $cart->total_price);
        $totalPriceForCart = $couponTotal['finalPrice'];
        $cart->forceFill([
            'coupon' => $coupon->code,
        ])->save();

        return [
            'total_price' => $totalPriceForCart,
            'coupon_discount' => round((float) $cart->total_price - (float) $totalPriceForCart, 2),
            'free_shipping' => $couponTotal['freeShipping'] ?? false,
        ];
    }
}

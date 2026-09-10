<?php

namespace Marvel\Http\Resources;

use App\Services\Coupon\CouponCalculator;
use App\Http\Resources\Coupons\CouponResource;
use App\Services\Currency\CurrencyService;
use App\Services\General\PromotionService;
use Illuminate\Http\Request;
use Marvel\Database\Models\Coupon;
use Marvel\Enums\ShippingMethod;

class CartResource extends Resource
{
    public function toArray(Request $request)
    {
        $items = $this->whenLoaded('items');

        if ($items) {
            $normalItems = $items->where('shipping_method', ShippingMethod::SCHEDULED)->values();
            $fastItems = $items->where('shipping_method', ShippingMethod::FAST)->values();
        } else {
            $normalItems = collect();
            $fastItems = collect();
        }

        $couponModel = $this->coupon ? Coupon::where('code', $this->coupon)->first() : null;
        $couponObject = $couponModel ? CouponResource::make($couponModel) : null;

        $subtotal = $items ? round((float) $items->sum('total_price'), 2) : 0;

        $couponDiscount = 0.0;
        if ($couponModel) {
            $calculation = CouponCalculator::calculate($couponModel, $subtotal);
            $couponDiscount = round((float) $calculation['discountAmount'], 2);
        }

        $promotionService = app(PromotionService::class);
        $currencyService = app(CurrencyService::class);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'coupon' => $couponObject,
            'coupon_code' => $this->coupon,
            'status' => $this->status,
            'reserved_at' => $this->reserved_at,
            'expires_at' => $this->expires_at,
            'total_items' => $items ? $items->count() : null,
            'total_quantity' => $items ? $items->sum('quantity') : null,
            'total_price' => $this->convertPrice($subtotal, $currencyService),
            'subtotal' => $this->convertPrice($subtotal, $currencyService),
            'coupon_discount' => $this->convertPrice($couponDiscount, $currencyService),
            'total_after_coupon' => $this->convertPrice(round(max(0, $subtotal - $couponDiscount), 2), $currencyService),
'currency' => $currencyService->getEffectiveCode(),
            'normal_items_count' => $normalItems->count(),
            'fast_items_count' => $fastItems->count(),
            'normal_items' => CartItemResource::collection($normalItems),
            'fast_items' => CartItemResource::collection($fastItems),
            'has_eligible_promotion' => $items && $items->isNotEmpty()
                ? $promotionService->hasEligiblePromotion($this->resource)
                : false,
        ];
    }

    private function convertPrice($value, CurrencyService $currencyService): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

return $currencyService->convertPrice(
            $value,
            $currencyService->getCatalogCode(),
            $currencyService->getEffectiveCode(),
        );
    }
}

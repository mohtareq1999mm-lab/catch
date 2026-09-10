<?php

namespace App\Services\Coupon;

use Marvel\Database\Models\Coupon;
use Marvel\Enums\DiscountType;

class CouponCalculator
{
    public static function calculate(Coupon $coupon, float $price): array
    {
        $discount = (float) $coupon->discount;
        $discountAmount = 0.0;

        if ($coupon->discount_type === DiscountType::PERCENTAGE) {
            $discountAmount = $price * ($discount / 100);

            if ($coupon->max_discount_amount !== null) {
                $discountAmount = min($discountAmount, (float) $coupon->max_discount_amount);
            }
        } elseif ($coupon->discount_type === DiscountType::FIXED_RATE) {
            $discountAmount = min($discount, $price);
        }

        $freeShipping = $coupon->discount_type === DiscountType::FREE_SHIPPING;

        $discountAmount = round(max(0, $discountAmount), 2);
        $finalPrice = round(max(0, $price - $discountAmount), 2);

        return [
            'discountAmount' => $discountAmount,
            'finalPrice' => $finalPrice,
            'discountType' => $coupon->discount_type,
            'freeShipping' => $freeShipping,
        ];
    }
}

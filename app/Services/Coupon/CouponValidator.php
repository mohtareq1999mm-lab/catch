<?php

namespace App\Services\Coupon;

use Illuminate\Support\Collection;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\User;

class CouponValidator
{
    public static function validate(Coupon $coupon, ?User $user = null, ?Collection $items = null): array
    {
        if (!$coupon->status) {
            return self::invalid('disabled', __('coupon.disabled'));
        }

        $today = today();

        if ($coupon->start_date && $coupon->start_date->gt($today)) {
            return self::invalid('not_active', __('coupon.not_yet_active'));
        }

        if ($coupon->end_date && $coupon->end_date->lt($today)) {
            return self::invalid('expired', __('coupon.expired'));
        }

        if ($coupon->limiter !== null && $coupon->used >= $coupon->limiter) {
            return self::invalid('usage_limit_reached', __('coupon.usage_limit_reached'));
        }

        if ($user) {
            $alreadyUsed = CouponUsage::where('coupon_id', $coupon->id)
                ->where('user_id', $user->id)
                ->whereNotNull('used_at')
                ->exists();

            if ($alreadyUsed) {
                return self::invalid('already_used', __('coupon.already_used'));
            }
        }

        if ($items !== null && $items->isNotEmpty()) {
            $restrictedProductIds = $coupon->products()->pluck('product_id')->toArray();

            if (!empty($restrictedProductIds)) {
                $cartProductIds = $items->pluck('product_id')->toArray();

                if (empty(array_intersect($restrictedProductIds, $cartProductIds))) {
                    return self::invalid('product_not_eligible', __('coupon.product_not_eligible'));
                }
            }
        }

        return self::valid($coupon);
    }

    public static function validateByCode(string $code, ?User $user = null, ?Collection $items = null): array
    {
        $coupon = Coupon::where('code', $code)->first();

        if (!$coupon) {
            return self::invalid('not_found', __('coupon.not_found'));
        }

        return self::validate($coupon, $user, $items);
    }

    private static function valid(Coupon $coupon): array
    {
        return [
            'valid' => true,
            'reason' => null,
            'message' => null,
            'coupon' => $coupon,
        ];
    }

    private static function invalid(string $reason, string $message): array
    {
        return [
            'valid' => false,
            'reason' => $reason,
            'message' => $message,
            'coupon' => null,
        ];
    }
}

<?php

namespace App\Services\Invoice\Validators;

use App\Contracts\Services\Invoice\SnapshotValidatorInterface;
use App\Exceptions\FinancialInvariantException;

class FinancialInvariantValidator implements SnapshotValidatorInterface
{
    private const TOLERANCE = 0.01;

    public function validate(array $snapshot): void
    {
        $pb = $snapshot['pricing_breakdown'];

        $subtotal = (float) ($pb['subtotal'] ?? 0);
        $promotionDiscount = (float) ($pb['promotion_discount'] ?? 0);
        $couponDiscount = (float) ($pb['coupon_discount'] ?? 0);
        $shippingPrice = (float) ($pb['shipping_price'] ?? 0);
        $fastShippingFee = (float) ($pb['fast_shipping_fee'] ?? 0);
        $declaredTotal = (float) ($pb['total'] ?? 0);

        $computedTotal = $subtotal - $promotionDiscount - $couponDiscount + $shippingPrice + $fastShippingFee;

        $diff = abs($computedTotal - $declaredTotal);

        if ($diff > self::TOLERANCE) {
            throw new FinancialInvariantException(
                sprintf(
                    'Financial invariant violation: subtotal(%s) - promotion(%s) - coupon(%s) + shipping(%s) + fast_shipping_fee(%s) = %s, but declared total is %s (diff: %s)',
                    $subtotal, $promotionDiscount, $couponDiscount, $shippingPrice, $fastShippingFee,
                    $computedTotal, $declaredTotal, $diff
                )
            );
        }
    }
}

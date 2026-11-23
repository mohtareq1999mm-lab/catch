<?php

namespace App\Services\Shipping;

use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Governorate;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ItemType;

/**
 * Single quote authority for checkout.
 *
 * Wraps the existing governorate shipping_prices rows and the existing
 * free-shipping precedence. It never accepts or persists a client price.
 */
class ShippingQuoteService
{
    /**
     * Existing governorate authority: id kept for shippable areas, zero price
     * for missing/inactive/unpriced areas, null id only for unknown ids.
     *
     * @return array{price: float, free_shipping_over: float|null, governorate_id: int|null}
     */
    public function governorateShippingInfo(?int $governorateId): array
    {
        $governorate = $governorateId ? Governorate::query()->find($governorateId) : null;
        if (!$governorate) {
            return ['price' => 0.0, 'free_shipping_over' => null, 'governorate_id' => null];
        }

        $shippingPrice = $governorate->status
            ? $governorate->shippingPrice()->where('status', true)->first()
            : null;

        return [
            'price' => $shippingPrice ? (float) $shippingPrice->price : 0.0,
            'free_shipping_over' => $shippingPrice?->free_shipping_over !== null
                ? (float) $shippingPrice->free_shipping_over
                : null,
            'governorate_id' => $governorateId,
        ];
    }

    /**
     * Charge for a cart using an already-resolved shipping info triple.
     * Shipping exists only for physical lines; digital-only carts ship nothing.
     */
    public function shippingForCart(Cart $cart, array $shippingInfo): float
    {
        $physicalSubtotal = $this->physicalLinesSubtotal($cart);

        if ($physicalSubtotal <= 0) {
            return 0.0;
        }

        return $this->applyFreeShippingThreshold(
            $physicalSubtotal,
            $shippingInfo['free_shipping_over'] ?? null,
            (float) ($shippingInfo['price'] ?? 0),
        );
    }

    /**
     * Full quote for an externally supplied subtotal (e.g. fast checkout).
     *
     * @return array{price: float, free_shipping_over: float|null, governorate_id: int|null, subtotal: float, shipping_amount: float}
     */
    public function quote(?int $governorateId, float $subtotal, ?string $couponDiscountType = null): array
    {
        $info = $this->governorateShippingInfo($governorateId);
        $baseShipping = (float) ($info['price'] ?? 0);
        $afterThreshold = $this->applyFreeShippingThreshold(
            $subtotal,
            $info['free_shipping_over'],
            $baseShipping,
        );
        $thresholdWaiver = max(0.0, round($baseShipping - $afterThreshold, 3));
        $shippingAmount = $this->applyCouponWaiver($couponDiscountType, $afterThreshold);
        $couponWaiver = max(0.0, round($afterThreshold - $shippingAmount, 3));

        return [
            'price' => $baseShipping,
            'free_shipping_over' => $info['free_shipping_over'],
            'governorate_id' => $info['governorate_id'],
            'subtotal' => $subtotal,
            'base_shipping' => $baseShipping,
            'threshold_waiver' => $thresholdWaiver,
            'coupon_waiver' => $couponWaiver,
            'shipping_amount' => $shippingAmount,
        ];
    }

    /** Scheduled checkout quote: the threshold base is the physical-lines subtotal. */
    public function quoteForCart(Cart $cart, ?int $governorateId, ?string $couponDiscountType = null): array
    {
        $physicalSubtotal = $this->physicalLinesSubtotal($cart);
        $quote = $this->quote($governorateId, $physicalSubtotal, $couponDiscountType);

        // Digital-only carts have nothing to ship. Keep the authoritative
        // rate in the projection for traceability, but never charge it.
        if ($physicalSubtotal <= 0) {
            $quote['base_shipping'] = 0.0;
            $quote['threshold_waiver'] = 0.0;
            $quote['coupon_waiver'] = 0.0;
            $quote['shipping_amount'] = 0.0;
        }

        return $quote;
    }

    public function applyFreeShippingThreshold(float $subtotal, ?float $freeShippingOver, float $shippingPrice): float
    {
        if ($freeShippingOver !== null && $subtotal > $freeShippingOver) {
            return 0.0;
        }

        return $shippingPrice;
    }

    public function applyCouponWaiver(?string $couponDiscountType, float $shippingPrice): float
    {
        return $couponDiscountType === DiscountType::FREE_SHIPPING ? 0.0 : $shippingPrice;
    }

    private function physicalLinesSubtotal(Cart $cart): float
    {
        $cart->loadMissing(['items.product']);

        return round((float) $cart->items->sum(function ($item) {
            if (($item->product?->item_type ?? ItemType::PHYSICAL) === ItemType::DIGITAL) {
                return 0;
            }

            return (float) ($item->total_price ?? 0);
        }), 2);
    }
}

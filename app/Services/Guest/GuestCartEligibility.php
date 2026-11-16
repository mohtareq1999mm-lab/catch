<?php

namespace App\Services\Guest;

use App\Contexts\ChannelContext;
use App\Enums\GuestCartRejectionReason;
use App\Exceptions\GuestCartRejectionException;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Enums\ItemType;
use Marvel\Enums\ShippingMethod;

/**
 * Guest Eligibility policy for one cart line.
 *
 * One home for "may this line exist as a guest?" so the rule is not restated
 * per consumer. Consumers load the product and pass the raw item fields; the
 * module owns the variant lookup, the stock arithmetic and the reason codes.
 *
 * Semantics (approved S11 / D2):
 *  - variant stock and in_stock are enforced ONLY when variant stock is known
 *    (stock_quantity > 0); `0/NULL` keeps the documented unlimited/unknown
 *    semantics, mirroring the product-level rule.
 *  - a variant outside the product, or a known-stock variant that is fully
 *    reserved/unavailable, is variant_unavailable.
 */
class GuestCartEligibility
{
    /**
     * @throws GuestCartRejectionException when the line is not guest-eligible
     */
    public function assertEligible(
        Product $product,
        ?int $variantId,
        int $quantity,
        string $shippingMethod
    ): void {
        if (($product->item_type ?? ItemType::PHYSICAL) === ItemType::DIGITAL) {
            throw new GuestCartRejectionException(
                GuestCartRejectionReason::DIGITAL_NOT_SUPPORTED,
                __('guest_checkout.digital_not_supported')
            );
        }

        if (
            config('channel.enabled', true)
            && app(ChannelContext::class)->isHome()
            && (bool) $product->is_fast_shipping_available
        ) {
            throw new GuestCartRejectionException(
                GuestCartRejectionReason::PRODUCT_UNAVAILABLE,
                __('guest_checkout.product_unavailable')
            );
        }

        $method = strtoupper($shippingMethod);

        // Guest checkout currently has no fast-shipping checkout path (the
        // canonical order builder consumes SCHEDULED lines only). Reject
        // instead of silently dropping the line later.
        if ($method !== ShippingMethod::SCHEDULED) {
            throw new GuestCartRejectionException(
                GuestCartRejectionReason::FAST_SHIPPING_NOT_SUPPORTED,
                __('guest_checkout.fast_shipping_not_supported')
            );
        }

        if (($product->product_type ?? null) === 'variable' && empty($variantId)) {
            throw new GuestCartRejectionException(
                GuestCartRejectionReason::VARIANT_REQUIRED,
                __('guest_checkout.variant_required')
            );
        }

        if (!empty($variantId)) {
            $variant = ProductVariant::query()
                ->whereKey($variantId)
                ->where('product_id', $product->id)
                ->first();

            if (!$variant) {
                throw new GuestCartRejectionException(
                    GuestCartRejectionReason::VARIANT_UNAVAILABLE,
                    __('guest_checkout.variant_unavailable')
                );
            }

            $variantStock = (int) ($variant->stock_quantity ?? 0);
            $variantAvailable = max(0, $variantStock - (int) ($variant->reserved_quantity ?? 0));

            if ($variantStock > 0 && $quantity > $variantAvailable) {
                throw new GuestCartRejectionException(
                    GuestCartRejectionReason::INSUFFICIENT_STOCK,
                    __('guest_checkout.insufficient_stock')
                );
            }

            if ($variantStock > 0 && !$variant->in_stock && $variantAvailable <= 0) {
                throw new GuestCartRejectionException(
                    GuestCartRejectionReason::VARIANT_UNAVAILABLE,
                    __('guest_checkout.variant_unavailable')
                );
            }
        }
    }
}

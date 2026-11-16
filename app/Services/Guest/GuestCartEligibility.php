<?php

namespace App\Services\Guest;

use App\Contexts\ChannelContext;
use App\Enums\GuestCartRejectionReason;
use App\Exceptions\GuestCartRejectionException;
use App\ValueObjects\GuestEligibilityVerdict;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Enums\ItemType;
use Marvel\Enums\ShippingMethod;

/**
 * Guest Eligibility policy for one cart line — the single source of truth
 * for both the cart-add path and Login Merge.
 *
 * `evaluate()` returns the verdict (allowed, reason, available quantity);
 * `assertEligible()` is the throwing wrapper the add path uses.
 *
 * Semantics (approved S11 / D2):
 *  - variant stock and in_stock are enforced ONLY when variant stock is known
 *    (stock_quantity > 0); `0/NULL` keeps the documented unlimited/unknown
 *    semantics, mirroring the product-level rule.
 *  - a variant outside the product, or a known-stock variant that is fully
 *    reserved/unavailable, is not eligible.
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
        $verdict = $this->evaluate($product, $variantId, $quantity, $shippingMethod);

        if (!$verdict->allowed) {
            throw new GuestCartRejectionException(
                $verdict->reason,
                __('guest_checkout.' . $verdict->reason->value)
            );
        }
    }

    public function evaluate(
        Product $product,
        ?int $variantId,
        int $quantity,
        string $shippingMethod
    ): GuestEligibilityVerdict {
        if (!$this->isActive($product)) {
            return GuestEligibilityVerdict::rejected(GuestCartRejectionReason::PRODUCT_UNAVAILABLE);
        }

        if (($product->item_type ?? ItemType::PHYSICAL) === ItemType::DIGITAL) {
            return GuestEligibilityVerdict::rejected(GuestCartRejectionReason::DIGITAL_NOT_SUPPORTED);
        }

        if (
            config('channel.enabled', true)
            && app(ChannelContext::class)->isHome()
            && (bool) $product->is_fast_shipping_available
        ) {
            return GuestEligibilityVerdict::rejected(GuestCartRejectionReason::PRODUCT_UNAVAILABLE);
        }

        // Guest checkout currently has no fast-shipping checkout path (the
        // canonical order builder consumes SCHEDULED lines only). Reject
        // instead of silently dropping the line later.
        if (strtoupper($shippingMethod) !== ShippingMethod::SCHEDULED) {
            return GuestEligibilityVerdict::rejected(GuestCartRejectionReason::FAST_SHIPPING_NOT_SUPPORTED);
        }

        if (($product->product_type ?? null) === 'variable' && empty($variantId)) {
            return GuestEligibilityVerdict::rejected(GuestCartRejectionReason::VARIANT_REQUIRED);
        }

        $productAvailable = $this->productAvailableQuantity($product);

        if (empty($variantId)) {
            if ($productAvailable !== null && $quantity > $productAvailable) {
                return GuestEligibilityVerdict::rejected(
                    GuestCartRejectionReason::INSUFFICIENT_STOCK,
                    $productAvailable
                );
            }

            return GuestEligibilityVerdict::allowed($productAvailable);
        }

        $variant = ProductVariant::query()
            ->whereKey($variantId)
            ->where('product_id', $product->id)
            ->first();

        if (!$variant) {
            return GuestEligibilityVerdict::rejected(GuestCartRejectionReason::VARIANT_UNAVAILABLE);
        }

        $variantStock = (int) ($variant->stock_quantity ?? 0);
        $variantAvailable = max(0, $variantStock - (int) ($variant->reserved_quantity ?? 0));

        // Effective line ceiling: known variant stock, tightened by the
        // product ceiling when both are known. 0/NULL keeps the documented
        // unlimited/unknown semantics.
        $ceiling = $variantStock > 0
            ? ($productAvailable === null ? $variantAvailable : min($variantAvailable, $productAvailable))
            : $productAvailable;

        if ($ceiling !== null && $quantity > $ceiling) {
            return GuestEligibilityVerdict::rejected(
                GuestCartRejectionReason::INSUFFICIENT_STOCK,
                $ceiling
            );
        }

        if ($variantStock > 0 && !$variant->in_stock && $variantAvailable <= 0) {
            return GuestEligibilityVerdict::rejected(
                GuestCartRejectionReason::VARIANT_UNAVAILABLE,
                $variantAvailable
            );
        }

        return GuestEligibilityVerdict::allowed($ceiling);
    }

    private function isActive(Product $product): bool
    {
        return Product::query()->active()->whereKey($product->id)->exists();
    }

    /** Known product ceiling, or null for unlimited/unknown. */
    private function productAvailableQuantity(Product $product): ?int
    {
        $stock = (int) ($product->stock_quantity ?? 0);

        if ($stock <= 0) {
            return null;
        }

        return max(0, $stock - (int) ($product->reserved_quantity ?? 0));
    }
}

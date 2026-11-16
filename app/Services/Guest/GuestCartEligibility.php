<?php

namespace App\Services\Guest;

use App\Contexts\ChannelContext;
use App\Enums\GuestCartRejectionReason;
use App\Exceptions\GuestCartRejectionException;
use App\ValueObjects\GuestEligibilityVerdict;
use Marvel\Database\Models\Cart;
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

        $variant = null;

        if (!empty($variantId)) {
            $variant = ProductVariant::query()->whereKey($variantId)->first();
        }

        return $this->evaluateLoaded($product, $variantId, $variant, $quantity, $shippingMethod);
    }

    /**
     * Verdicts for a whole cart with a CONSTANT number of queries: one
     * batched product lookup inside the active/channel scopes and one
     * batched variant lookup, then per-line in-memory evaluation.
     *
     * @return array<int, GuestEligibilityVerdict> keyed by cart item id
     */
    public function evaluateCart(Cart $cart): array
    {
        $items = $cart->items;

        if ($items->isEmpty()) {
            return [];
        }

        $products = Product::query()->active()
            ->whereIn('id', $items->pluck('product_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $variantIds = $items->pluck('product_variant_id')->filter()->unique();
        $variants = $variantIds->isEmpty()
            ? collect()
            : ProductVariant::query()->whereIn('id', $variantIds)->get()->keyBy('id');

        $verdicts = [];

        foreach ($items as $item) {
            $product = $products->get($item->product_id);

            if (!$product) {
                $verdicts[$item->id] = GuestEligibilityVerdict::rejected(
                    GuestCartRejectionReason::PRODUCT_UNAVAILABLE
                );
                continue;
            }

            $variantId = $item->product_variant_id ? (int) $item->product_variant_id : null;

            $verdicts[$item->id] = $this->evaluateLoaded(
                $product,
                $variantId,
                $variantId ? $variants->get($variantId) : null,
                (int) $item->quantity,
                (string) $item->shipping_method
            );
        }

        return $verdicts;
    }

    /**
     * One line against the already-resolved product/variant: the caller owns
     * resolution, this method owns the rules.
     */
    private function evaluateLoaded(
        Product $product,
        ?int $variantId,
        ?ProductVariant $variant,
        int $quantity,
        string $shippingMethod
    ): GuestEligibilityVerdict {
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

        if (!$variant || (int) $variant->product_id !== (int) $product->id) {
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

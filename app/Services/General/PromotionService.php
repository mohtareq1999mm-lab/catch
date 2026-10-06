<?php

declare(strict_types=1);

namespace App\Services\General;

use App\DTOs\CheckoutTotals;
use App\Services\General\PromotionEngine\PromotionEligibilityResolver;
use App\Services\General\PromotionEngine\PromotionApplicator;
use App\Services\General\PromotionEngine\Outcome\DiscountOutcome;
use App\Services\General\PromotionEngine\DTOs\GiftItem;
use App\Services\General\PromotionEngine\PromotionResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\Promotion;
use Marvel\Enums\ShippingMethod;

class PromotionService
{
    public function __construct(
        private PromotionEligibilityResolver $resolver,
        private PromotionApplicator $applicator,
    ) {}

    public function eligiblePromotions(Cart $cart): Collection
    {
        $cart->load(['items.product', 'items.productVariant']);

        $subtotal = $this->subtotal($cart);
        $subtotalCents = (int) round((float) $subtotal * 100);
        $promotions = Promotion::valid()
            ->with([
                'products:id',
                'giftProducts:id,name,sku,product_type,stock_quantity,reserved_quantity',
                'giftProducts.variations:id,product_id,stock_quantity,reserved_quantity,price,height,width,length,weight',
                'giftProducts.variations.attributeProducts.attributeValue.attribute',
            ])
            ->get();

        return $this->resolver->eligible($cart, $promotions, $subtotalCents);
    }

    public function eligiblePromotionsPayload(Cart $cart): array
    {
        return [
            'eligible_promotions' => $this->eligiblePromotions($cart)
                ->map(fn(PromotionResult $result) => $result->toArray())
                ->values()
                ->all(),
        ];
    }

    public function applySelectedPromotion(Cart $cart, ?int $promotionId, ?int $selectedGiftProductId = null, ?string $shippingMethod = null): CheckoutTotals
    {
        $this->removeLegacyGiftRows($cart);
        $cart->items->load(['product', 'productVariant']);

        $subtotal = $this->subtotal($cart);
        $subtotalCents = (int) round((float) $subtotal * 100);
        $result = null;
        $discountDetails = ['discount' => 0.0, 'gift_items' => []];
        $giftDetails = ['discount' => 0.0, 'gift_items' => []];

        if ($promotionId) {
            $promotion = Promotion::valid()
                ->whereKey($promotionId)
                ->with([
                    'products:id',
                    'giftProducts:id,name,sku,product_type,stock_quantity,reserved_quantity',
                    'giftProducts.variations:id,product_id,stock_quantity,reserved_quantity,price,height,width,length,weight',
                    'giftProducts.variations.attributeProducts.attributeValue.attribute',
                ])
                ->lockForUpdate()
                ->first();

            if (!$promotion) {
                throw new \InvalidArgumentException('Selected promotion is not valid.');
            }

            // Evaluate promotion (read-only)
            $result = $this->resolver->resolve($cart, $promotion, $subtotalCents);

            if (!$result) {
                throw new \InvalidArgumentException('Selected promotion is not eligible for this cart.');
            }

            // PromotionResult from resolver already contains matchedSubtotalCents computed during resolve()
            $amountCents = (int) round((float) ($result->discount ?? 0) * 100);

            if ($amountCents > 0) {
                $discountOutcome = new DiscountOutcome($amountCents, $result->matchedSubtotalCents);
                $discountDetails = $this->applicator->applyOutcome($cart, $promotion, $discountOutcome);
                $itemIds = $cart->items->pluck('id');
                $cart->refresh();
                $cart->load(['items' => fn($q) => $q->whereIn('id', $itemIds), 'items.product', 'items.productVariant']);
            }

            if (!empty($result->giftItems)) {
                // Gift promotions resolve to ORDER-LINE DESCRIPTORS only.
                // The cart is never mutated and no inventory is reserved here —
                // the gift line is created and reserved atomically with the
                // Order during checkout (OrderReservationService).
                $selectedGiftItem = $this->resolveSelectedGiftItem($result->giftItems, $selectedGiftProductId);
                $giftDetails = [
                    'discount' => 0.0,
                    'gift_items' => [[
                        'product_id' => $selectedGiftItem->productId,
                        'product_variant_id' => $selectedGiftItem->productVariantId,
                        'quantity' => max(1, (int) $selectedGiftItem->quantity),
                        'promotion_id' => $promotion->id,
                    ]],
                ];
            } elseif ($selectedGiftProductId) {
                // The user explicitly requested a gift the engine could not
                // offer (e.g. out of stock). Fail loudly instead of silently
                // dropping the promised gift from the order.
                throw new \InvalidArgumentException('Selected gift product is not available for this promotion.');
            }
        } else {
            return $this->clearPromotionFromCart($cart);
        }

        // Calculate finalTotal from actual cart item prices after promotion application
        $finalTotal = round(
            (float) $cart->items
                ->reject(fn($item) => (bool) ($item->is_gift ?? false))
                ->sum('total_price'),
            2
        );

        // Calculate promotion discount
        $promotionDiscount = round((float) ($discountDetails['discount'] ?? 0), 2);

        // FINANCIAL INVARIANT FIX: Ensure subtotal - promotionDiscount = finalTotal
        // by deriving subtotal from the actual post-promotion state.
        // This prevents rounding discrepancies from per-item promotion application.
        $calculatedSubtotal = round($finalTotal + $promotionDiscount, 2);

        return new CheckoutTotals(
            subtotal: $calculatedSubtotal,
            promotionDiscount: $promotionDiscount,
            couponDiscount: 0,
            finalTotal: $finalTotal,
            promotion: $result ? [
                'id' => $result->promotion->id,
                'type' => $result->promotion->type_amount,
                'code' => $result->promotion->code,
            ] : null,
            giftItems: $giftDetails['gift_items'] ?? [],
        );
    }

    public function clearPromotionFromCart(Cart $cart): CheckoutTotals
    {
        $this->removeLegacyGiftRows($cart);
        $cart->items()
            ->where(function ($q) {
                $q->whereNotNull('promotion_id')->orWhere('discount_amount', '>', 0);
            })
            ->update([
                'promotion_id' => null,
                'discount_amount' => 0,
                'total_price' => DB::raw('ROUND(price * quantity, 2)'),
            ]);
        $cart->refresh();
        $cart->load(['items.product', 'items.productVariant']);

        $subtotal = $this->subtotal($cart);
        $undiscountedTotal = round((float) $cart->items
            ->reject(fn($item) => (bool) ($item->is_gift ?? false))
            ->sum(fn($item) => ((float) ($item->price ?? 0)) * ((int) ($item->quantity ?? 0))), 2);
        $cart->forceFill(['total_price' => $undiscountedTotal])->save();

        return new CheckoutTotals(
            subtotal: $subtotal,
            promotionDiscount: 0.0,
            couponDiscount: 0.0,
            finalTotal: $undiscountedTotal,
            promotion: null,
            giftItems: [],
        );
    }

    /**
     * Consume one unit of the promotion limiter after payment/completion.
     *
     * The update is limiter-guarded under a row lock: when the limiter
     * filled between apply and completion the statement matches no row and
     * nothing is written (F-06 customer-favoring trade-off — the order keeps
     * its approved discount; see finalizePromotionUsageAfterPayment, which
     * makes the no-op observable).
     *
     * @return bool true when the counter actually moved, false when the call
     *              was a no-op (null id, missing row, or limiter already full).
     */
    public function incrementUsage(?int $promotionId): bool
    {
        if (!$promotionId) {
            return false;
        }

        $moved = Promotion::query()
            ->whereKey($promotionId)
            ->where(function ($query) {
                $query->whereNull('limiter')
                    ->orWhereColumn('usage', '<', 'limiter');
            })
            ->lockForUpdate()
            ->first()
            ?->increment('usage');

        return (bool) $moved;
    }

    public function hasEligiblePromotion(Cart $cart): bool
    {
        if ($cart->items->isEmpty()) {
            return false;
        }

        return $this->eligiblePromotions($cart)->isNotEmpty();
    }

    public function decrementUsage(?int $promotionId): void
    {
        if (!$promotionId) {
            return;
        }

        Promotion::query()
            ->whereKey($promotionId)
            ->where('usage', '>', 0)
            ->lockForUpdate()
            ->first()
            ?->decrement('usage');
    }

    /**
     * F-04 — map a checkout reservation failure to the gift-specific 422.
     *
     * Call ONLY from a checkout catch block after OrderReservationService
     * threw InsufficientStockException for an order carrying promotion gift
     * descriptors (CheckoutTotals->giftItems). Gift availability is a
     * two-stage check by design (apply-time snapshot, order-time atomic
     * reservation against the shared stock pool): stock lost in between
     * must surface as the gift failure the apply path already defines —
     * never a silent drop, never a generic error for a promised gift.
     *
     * Re-reads each gift row under lock. Throws InvalidArgumentException
     * ('Selected gift product is not available for this promotion.') when a
     * selected gift is genuinely short. Returns silently when every gift is
     * still available — the shortage then concerns another line and the
     * caller must rethrow the original generic stock error.
     *
     * Read-only: never reserves, never mutates, never touches non-gift rows.
     */
    public function throwIfGiftUnavailable(array $giftItems): void
    {
        foreach ($giftItems as $gift) {
            $productId = (int) ($gift['product_id'] ?? 0);
            $variantId = isset($gift['product_variant_id']) && $gift['product_variant_id'] !== null
                ? (int) $gift['product_variant_id']
                : null;
            $quantity = max(1, (int) ($gift['quantity'] ?? 1));

            if (!$this->isGiftStockAvailable($productId, $variantId, $quantity)) {
                throw new \InvalidArgumentException('Selected gift product is not available for this promotion.');
            }
        }
    }

    /**
     * Locked availability re-check for one gift descriptor. Mirrors the
     * snapshot rules of GiftPromotionStrategy (simple counters, pinned
     * variant counters, any-variant fallback) but under lockForUpdate so the
     * verdict cannot be invalidated before the caller aborts. Missing rows
     * fail closed (unavailable).
     */
    private function isGiftStockAvailable(int $productId, ?int $variantId, int $quantity): bool
    {
        if ($productId <= 0) {
            return false;
        }

        if ($variantId) {
            $variant = ProductVariant::query()->whereKey($variantId)->lockForUpdate()->first();
            if (!$variant || (int) ($variant->product_id ?? 0) !== $productId) {
                return false;
            }

            return max(0, (int) ($variant->stock_quantity ?? 0) - (int) ($variant->reserved_quantity ?? 0)) >= $quantity;
        }

        $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
        if (!$product) {
            return false;
        }

        if (max(0, (int) ($product->stock_quantity ?? 0) - (int) ($product->reserved_quantity ?? 0)) >= $quantity) {
            return true;
        }

        // Variable product without a pinned variant may still satisfy the
        // gift from any stocked variant.
        if (method_exists($product, 'variations')) {
            return $product->variations()
                ->whereRaw('(COALESCE(stock_quantity, 0) - COALESCE(reserved_quantity, 0)) >= ?', [$quantity])
                ->exists();
        }

        return false;
    }

    /**
     * Purge legacy gift CartItems (pre order-owned-reservation artifacts).
     * No inventory release: carts no longer own reservations.
     */
    private function removeLegacyGiftRows(Cart $cart): void
    {
        $cart->items()->where('is_gift', true)->delete();
    }

    private function subtotal(Cart $cart): float
    {
        return round((float) $cart->items
            ->reject(fn($item) => (bool) ($item->is_gift ?? false))
            ->sum(function ($item) {
                $baseLineTotal = ((float) ($item->price ?? 0)) * ((int) ($item->quantity ?? 0));

                if ($baseLineTotal > 0) {
                    return $baseLineTotal;
                }

                return (float) ($item->total_price ?? 0);
            }), 2);
    }

    private function resolveSelectedGiftItem(array $giftItems, ?int $selectedGiftProductId): GiftItem
    {
        $availableGiftItems = collect($giftItems)
            ->filter(fn($giftItem) => (int) ($giftItem['price_cents'] ?? 0) === 0)
            ->values();

        if ($availableGiftItems->isEmpty()) {
            throw new \InvalidArgumentException('No available gift products for this promotion.');
        }

        if ($selectedGiftProductId) {
            $selectedGiftItem = $availableGiftItems->firstWhere('product_id', $selectedGiftProductId);

            if (!$selectedGiftItem) {
                throw new \InvalidArgumentException('Selected gift product is not available for this promotion.');
            }

            return $selectedGiftItem;
        }

        return $availableGiftItems->first();
    }
}

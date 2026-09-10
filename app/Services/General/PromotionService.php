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

    public function incrementUsage(?int $promotionId): void
    {
        if (!$promotionId) {
            return;
        }

        Promotion::query()
            ->whereKey($promotionId)
            ->where(function ($query) {
                $query->whereNull('limiter')
                    ->orWhereColumn('usage', '<', 'limiter');
            })
            ->lockForUpdate()
            ->first()
            ?->increment('usage');
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

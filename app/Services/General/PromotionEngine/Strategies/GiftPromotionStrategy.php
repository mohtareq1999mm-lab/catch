<?php

declare(strict_types=1);

namespace App\Services\General\PromotionEngine\Strategies;

use App\Services\General\PromotionEngine\Contracts\PromotionStrategy;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Promotion;
use App\Services\General\PromotionEngine\PromotionEvaluation;
use App\Services\General\PromotionEngine\Outcome\GiftOutcome;
use App\Services\General\PromotionEngine\DTOs\GiftItem;
use App\Services\General\PromotionEngine\Outcome\PromotionOutcome;

class GiftPromotionStrategy extends AbstractPromotionStrategy implements PromotionStrategy
{
    public function eligible(Promotion $promotion, Cart $cart, int $subtotal, PromotionEvaluation $evaluation): bool
    {
        return parent::eligible($promotion, $cart, $subtotal, $evaluation)
            && $promotion->giftProducts->isNotEmpty();
    }

    public function computeOutcome(Promotion $promotion, Cart $cart, int $subtotal, PromotionEvaluation $evaluation): PromotionOutcome
    {
        $giftItems = $promotion->giftProducts
            ->map(function ($product) {
                $variantId = (int) ($product->pivot->product_variant_id ?? 0);
                $variant = $variantId ? $this->resolveVariant($product, $variantId) : null;

                if ($variantId && (!$variant || (int) ($variant->available_stock ?? 0) <= 0)) {
                    return null;
                }

                if (!$variantId && !$this->hasAvailableStock($product)) {
                    return null;
                }

                $quantity = max(1, (int) ($product->pivot->quantity ?? 1));
                $variantPayload = $variant ? $this->variantPayload($variant, $product) : null;

                return new GiftItem(
                    (int) $product->id,
                    $variantId > 0 ? $variantId : null,
                    $variantPayload,
                    (string) $product->name,
                    (string) $product->sku,
                    method_exists($product, 'getFirstMediaUrl') ? $product->getFirstMediaUrl('products') : null,
                    $quantity,
                    0,
                    true
                );
            })
            ->filter()
            ->values()
            ->all();

        return new GiftOutcome($giftItems);
    }

    private function hasAvailableStock($product): bool
    {
        if (method_exists($product, 'isSimple') && $product->isSimple()) {
            return (int) ($product->available_stock ?? 0) > 0;
        }

        if (method_exists($product, 'variations')) {
            if ($product->relationLoaded('variations')) {
                return $product->variations
                    ->contains(fn($variant) => (int) ($variant->available_stock ?? 0) > 0);
            }

            return $product->variations()
                ->whereRaw('(COALESCE(stock_quantity, 0) - COALESCE(reserved_quantity, 0)) > 0')
                ->exists();
        }

        return (int) ($product->available_stock ?? 0) > 0;
    }

    private function resolveVariant($product, int $variantId)
    {
        if ($product->relationLoaded('variations')) {
            return $product->variations->firstWhere('id', $variantId);
        }

        if (method_exists($product, 'variations')) {
            return $product->variations()->whereKey($variantId)->first();
        }

        return null;
    }

    private function variantPayload($variant, $product): array
    {
        $attributes = [];
        if (isset($variant->attributeProducts)) {
            $attributes = $variant->attributeProducts->map(function ($attrProduct) {
                return [
                    'attribute_name' => optional(optional($attrProduct->attributeValue)->attribute)->name,
                    'value' => optional($attrProduct->attributeValue)->value,
                ];
            })->values()->all();
        }

        return [
            'id' => $variant->id,
            'price' => $variant->price ?? null,
            'current_price' => 0,
            'height' => $variant->height ?? null,
            'width' => $variant->width ?? null,
            'length' => $variant->length ?? null,
            'weight' => $variant->weight ?? null,
            'available_stock' => $variant->available_stock ?? null,
            'attributes' => $attributes,
        ];
    }
}

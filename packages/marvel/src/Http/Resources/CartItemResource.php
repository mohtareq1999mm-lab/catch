<?php

namespace Marvel\Http\Resources;

use App\Http\Resources\Product\ProductMiniResource;
use App\Contexts\ChannelContext;
use App\Services\Currency\CurrencyService;
use Illuminate\Http\Request;
use Marvel\Http\Resources\ProductVariantResource;

class CartItemResource extends Resource
{
    public function toArray(Request $request)
    {
        $availability = $this->availability();

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'quantity' => $this->quantity,
            'price' => $this->convertPrice($this->price),
            'total_price' => $this->convertPrice($this->total_price),
            'attributes' => $this?->attributes,
            'shipping_method' => $this->shipping_method,
            'promotion_id' => $this->promotion_id,
            'discount_amount' => $this->convertPrice($this->discount_amount),
            'is_gift' => $this->is_gift ?? false,
            'item_type' => $this->product?->item_type ?? \Marvel\Enums\ItemType::PHYSICAL,
            'product' => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
                'thumbnail' => $this->product->getFirstMediaUrl('products'),
            ] : null,
            // Additive availability signals so the frontend can explain
            // preserved-but-unavailable lines after a guest cart merge.
            'is_available' => $availability['available'],
            'unavailable_reason' => $availability['reason'],
        ];
    }

    /**
     * Presentational availability only; checkout remains the authority and
     * revalidates products/stock at order creation.
     *
     * @return array{available: bool, reason: ?string}
     */
    private function availability(): array
    {
        $product = $this->product;

        if (!$product) {
            return ['available' => false, 'reason' => 'product_deleted'];
        }

        if (method_exists($product, 'trashed') && $product->trashed()) {
            return ['available' => false, 'reason' => 'product_deleted'];
        }

        if (!$product->status) {
            return ['available' => false, 'reason' => 'product_unavailable'];
        }

        $availableQty = (int) ($product->stock_quantity ?? 0) - (int) ($product->reserved_quantity ?? 0);
        if (!$product->in_stock && $availableQty <= 0) {
            return ['available' => false, 'reason' => 'product_unavailable'];
        }

        if (
            config('channel.enabled', true)
            && app(ChannelContext::class)->isHome()
            && (bool) $product->is_fast_shipping_available
        ) {
            return ['available' => false, 'reason' => 'channel_not_eligible'];
        }

        if ($this->product_variant_id && $this->relationLoaded('productVariant')) {
            $variant = $this->productVariant;

            if (!$variant) {
                return ['available' => false, 'reason' => 'variant_unavailable'];
            }

            $variantAvailable = (int) ($variant->stock_quantity ?? 0) - (int) ($variant->reserved_quantity ?? 0);
            if ((int) ($variant->stock_quantity ?? 0) > 0 && $variantAvailable <= 0) {
                return ['available' => false, 'reason' => 'variant_unavailable'];
            }
        }

        return ['available' => true, 'reason' => null];
    }

    private function convertPrice($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $currencyService = app(CurrencyService::class);

return $currencyService->convertPrice(
            $value,
            $currencyService->getCatalogCode(),
            $currencyService->getEffectiveCode(),
        );
    }
}

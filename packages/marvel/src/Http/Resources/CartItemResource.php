<?php

namespace Marvel\Http\Resources;

use App\Http\Resources\Product\ProductMiniResource;
use App\Services\Currency\CurrencyService;
use Illuminate\Http\Request;
use Marvel\Http\Resources\ProductVariantResource;

class CartItemResource extends Resource
{
    public function toArray(Request $request)
    {
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
        ];
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

<?php

namespace Marvel\Http\Resources\Order;

use Illuminate\Http\Request;
use Marvel\Http\Resources\Resource;

class OrderItemResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'product_name' => $this->product_name,
            'product_sku' => $this->product_sku,
            'quantity' => (int) $this->product_quantity,
            'unit_price' => $this->roundMoney($this->product_price),
            'total_price' => $this->roundMoney($this->product_total_price),
            'discount_price' => $this->roundMoney($this->product_discount_price),
            'flash_sale_price' => $this->roundMoney($this->product_flash_sale_price),
            'promotion_discount_amount' => $this->roundMoney($this->promotion_discount_amount),
            'is_gift' => (bool) ($this->is_gift ?? false),
            'promotion_id' => $this->promotion_id,
            'attributes' => $this->attributes,
            'product' => $this->when($this->relationLoaded('product') && $this->product, function () {
                return [
                    'id' => $this->product->id,
                    'name' => $this->product->name,
                    'slug' => $this->product->slug,
                    'image' => $this->product->getFirstMediaUrl('product-image') ?: null,
                ];
            }),
            'variant' => $this->when($this->product_variant_id && $this->relationLoaded('productVariant') && $this->productVariant, function () {
                return [
                    'id' => $this->productVariant->id,
                    'sku' => $this->productVariant->sku,
                    'price' => $this->roundMoney($this->productVariant->price),
                    'in_stock' => (bool) $this->productVariant->in_stock,
                    'attributes' => $this->productVariant->relationLoaded('attributeProducts')
                        ? $this->productVariant->attributeProducts->map(fn ($attr) => [
                            'value_id' => $attr->attribute_value_id,
                            'value' => $attr->attributeValue?->getTranslation('value', app()->getLocale()),
                        ])->values()
                        : null,
                ];
            }),
        ];
    }

    private function roundMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }
}

<?php

namespace App\Http\Resources\Wms\Traits;

trait FormatsWmsProductVariant
{
    /**
     * @param mixed $product
     * @return array<string, mixed>|null
     */
    protected function formatProduct(mixed $product): ?array
    {
        if (!$product) {
            return null;
        }

        $thumbnail = null;
        if (method_exists($product, 'getFirstMediaUrl')) {
            $thumbnail = $product->getFirstMediaUrl('products') ?: ($product->image['thumbnail'] ?? null);
        }

        return [
            'id' => (int) $product->id,
            'name' => method_exists($product, 'getTranslation')
                ? $product->getTranslation('name', app()->getLocale())
                : ($product->name ?? null),
            'sku' => $product->sku ?? null,
            'product_type' => $product->product_type ?? null,
            'thumbnail' => $thumbnail,
        ];
    }

    /**
     * @param mixed $variant
     * @return array<string, mixed>|null
     */
    protected function formatVariant(mixed $variant): ?array
    {
        if (!$variant) {
            return null;
        }

        return [
            'id' => (int) $variant->id,
            'sku' => $variant->sku ?? null,
            'price' => (float) ($variant->price ?? 0),
            'stock_quantity' => (int) ($variant->stock_quantity ?? 0),
            'attributes' => $this->variantAttributes($variant),
        ];
    }

    /**
     * @param mixed $variant
     * @return array<int, array<string, mixed>>
     */
    protected function variantAttributes(mixed $variant): array
    {
        if (!$variant || !$variant->relationLoaded('attributeProducts')) {
            return [];
        }

        $out = [];
        foreach ($variant->attributeProducts as $ap) {
            $av = $ap->attributeValue ?? null;
            $attr = $av?->attribute ?? null;
            if ($av) {
                $out[] = [
                    'attribute' => $attr?->name ?? $attr?->slug ?? null,
                    'value' => $av->value ?? $av->name ?? null,
                    'attribute_id' => $attr?->id ?? null,
                    'attribute_value_id' => $av->id ?? null,
                ];
            }
        }

        return $out;
    }
}

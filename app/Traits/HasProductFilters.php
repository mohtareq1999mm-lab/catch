<?php

namespace App\Traits;

trait HasProductFilters
{
    /**
     * Get the dynamic filters for the product instance.
     *
     * @param \Marvel\Database\Models\Product $product
     * @return array
     */
    protected function getProductFilters($product): array
    {
        $filters = [];

        // 1. Brands
        $brandsRelation = $product->relationLoaded('brands') ? $product->brands : $product->brands()->get();
        $brands = $brandsRelation->map(fn($b) => $b->name)->filter()->values()->toArray();
        if (!empty($brands)) {
            $filters['brand'] = $brands;
        }

        // 2. Categories
        $categoriesRelation = $product->relationLoaded('categories') ? $product->categories : $product->categories()->get();
        $categories = $categoriesRelation->map(fn($c) => $c->name)->filter()->values()->toArray();
        if (!empty($categories)) {
            $filters['category'] = $categories;
        }

        // 3. Dimensions
        $dimensions = ['height', 'width', 'length', 'weight'];
        foreach ($dimensions as $column) {
            $productVal = $product->$column;
            $variantVals = $product->relationLoaded('variations') ? $product->variations->pluck($column)->toArray() : $product->variations()->pluck($column)->toArray();
            $values = array_values(array_unique(array_filter(array_merge([$productVal], $variantVals), fn($val) => $val !== null && $val !== '')));
            if (!empty($values)) {
                $filters[$column] = array_map('strval', $values);
            }
        }

        // 4. Attributes
        $variations = $product->relationLoaded('variations') ? $product->variations : $product->variations()->get();
        foreach ($variations as $variant) {
            $attrProducts = $variant->relationLoaded('attributeProducts') ? $variant->attributeProducts : $variant->attributeProducts()->get();
            foreach ($attrProducts as $attrProduct) {
                $attrVal = $attrProduct->relationLoaded('attributeValue') ? $attrProduct->attributeValue : $attrProduct->attributeValue()->first();
                if ($attrVal) {
                    $attribute = $attrVal->relationLoaded('attribute') ? $attrVal->attribute : $attrVal->attribute()->first();
                    if ($attribute) {
                        $filters[$attribute->slug][] = $attrVal->value;
                    }
                }
            }
        }

        // Unique and format attribute values
        foreach ($filters as $key => $values) {
            if (!in_array($key, ['brand', 'category', 'height', 'width', 'length', 'weight'])) {
                $filters[$key] = array_values(array_unique($values));
            }
        }

        return $filters;
    }
}

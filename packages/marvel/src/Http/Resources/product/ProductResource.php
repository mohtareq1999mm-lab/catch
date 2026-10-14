<?php

namespace Marvel\Http\Resources;

use App\Services\Tax\ProductTaxPresenter;

class ProductResource extends Resource
{
    public function toArray($request): array
    {
        $product = $this->resource instanceof \Marvel\Database\Models\Product ? $this->resource : $this;
        // Admin semantics: current_price stays the pre-tax effective price;
        // the tax-inclusive value is exposed through price_including_tax.
        $tax = app(ProductTaxPresenter::class)->describe($product, (float) $product->current_price);
        $priceIncludingTax = app(ProductTaxPresenter::class)->applyTo($product, (float) $product->current_price);

        return [
            'id'                     => $this->id,
            'name'                   => request()->routeIs('products.index') ? $this->getTranslation('name', app()->getLocale()) : $this->getRawOriginal('name'),
            'slug'                   => $this->slug,
            'description'            => request()->routeIs('products.index') ? $this->getTranslation('description', app()->getLocale()) : $this->getRawOriginal('description'), // Array فيه en/ar`
            'price'                  => $this->roundMoney($this->price),
            'current_price'          => $this->roundMoney($this->current_price),
            'tax_enabled'            => (bool) $this->tax_enabled,
            'tax_rate'               => $this->tax_rate !== null ? (float) $this->tax_rate : null,
            'tax'                    => $tax,
            'price_including_tax'    => $this->roundMoney($priceIncludingTax),
            'price_after_discount'    => $this->roundMoney($this->price_after_discount),
            'price_after_flash_sale'  => $this->roundMoney($this->price_after_flash_sale),
            'discount_type'          => $this->discount_type,
            'discount_amount'        => $this->discount_amount,
            'start_date'             => $this->start_date,
            'end_date'               => $this->end_date,
            'sku'                    => $this->sku,
            'stock_quantity'         => $this->stock_quantity,
            'reserved_quantity'      => $this->reserved_quantity ?? 0,
            'available_stock'        => $this->available_stock,
            'quantity'               => $this->quantity,
            'sold_quantity'          => $this->sold_quantity ?? 0,
            'in_stock'               => $this->in_stock,
            'status'                 => $this->status,
            'product_type'           => $this->product_type,
            'item_type'              => $this->item_type,
            'height'                 => $this->height,
            'width'                  => $this->width,
            'length'                 => $this->length,
            'weight'                 => $this->weight,
            'has_flash_sale'         => $this->has_flash_sale,
            'has_discount'           => $this->has_discount,
            'is_fast_shipping_available' => (bool) $this->is_fast_shipping_available,
            $this->mergeWhen($this->has_discount, fn() => ['discount_valid' => $this->isDiscountActive()]),
            'tags'                   => $this->whenLoaded('tags', fn() => TagResource::collection($this->tags)),
            'brands'                 => $this->whenLoaded('brands', fn() => BrandResource::collection($this->brands)),
            'banners'                => $this->whenLoaded('banners', fn() => BannerResource::collection($this->banners)),
            'sliders'                => $this->whenLoaded('sliders', fn() => SliderResource::collection($this->sliders)),
            'created_at'             => $this->created_at ? $this->created_at->toIso8601String() : null,
            'categories'            => $this->whenLoaded('categories', fn() => $this->getCategories($this->categories)),
            'flash_sales'          => $this->whenLoaded('flash_sales', fn() => FlashSaleResource::collection($this->flash_sales)),
            'reviews'              => $this->whenLoaded('reviews', fn() => ReviewResource::collection($this->reviews)),
            "images"                 => $this->getmedia('products') ? $this->getmediaImages('products') : [],
            "variants"                => $this->whenLoaded('variations', fn() => $this->getVariants()),
            $this->mergeWhen($this->relationLoaded('related_products'), fn() => ['related_products' => ProductResource::collection($this->related_products)]),
        ];
    }


    private function getCategories($categories)
    {
        return $categories->map(function ($category) {
            return [
                'id' => $category->id,
                'name' => $category->getTranslation('name', app()->getLocale()),
                'slug' => $category->slug,
            ];
        });
    }

    private function getShops($shops)
    {
        return $shops->map(function ($shop) {
            return [
                'id' => $shop->id,
                'name' => $shop->getTranslation('name', app()->getLocale()),
                'slug' => $shop->slug,
            ];
        });
    }

    private function getmediaImages($collection)
    {
        return $this->getmedia($collection)->map(function ($media) {
            return $media->getUrl();
        });
    }

    private function getVariants()
    {
        return $this->variations->map(function ($variant) {
            return [
                'id' => $variant->id,
                'price' => $this->roundMoney($variant->price),
                'current_price' => $this->roundMoney($variant->current_price),
                'stock_quantity' => $variant->stock_quantity,
                'reserved_quantity' => $variant->reserved_quantity ?? 0,
                'available_stock' => $variant->available_stock,
                'quantity' => $variant->quantity,
                                // 'sold_quantity' => $variant->sold_quantity,

                'height' => $variant->height,
                'width' => $variant->width,
                'length' => $variant->length,
                'weight' => $variant->weight,
                'attributes' => $this->getAttributeName($variant->attributeProducts),
            ];
        });
    }

    private function getAttributeName($attributeProducts)
    {
        return $attributeProducts->map(function ($attrProduct) {
            return [
                'attribute_name' => optional(optional($attrProduct->attributeValue)->attribute)->name,
                'value' => optional($attrProduct->attributeValue)->value,
            ];
        });
    }

    private function roundMoney($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }
}

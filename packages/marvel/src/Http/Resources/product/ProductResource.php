<?php

namespace Marvel\Http\Resources;

use App\Services\General\ProductService;
use App\Services\Tax\ProductTaxPresenter;
use App\Traits\HasChannelFilter;

class ProductResource extends Resource
{
    use HasChannelFilter;

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
            // Barcode attributes (one product_barcodes row per product).
            'piece_barcode'          => $product->barcodes?->piece_barcode,
            'carton_barcode'         => $product->barcodes?->carton_barcode,
            'pieces_per_carton'      => $product->barcodes?->pieces_per_carton,
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
            'locations'              => $this->formatLocations($this->productLocations),
            "variants"               => $this->whenLoaded('variations', fn() => $this->getVariants()),
            $this->mergeWhen($this->relationLoaded('related_products'), fn() => ['related_products' => ProductResource::collection($this->related_products)]),
            // Manual cross-selling (Q-01/Q-02/Q-03, plan rev.3 §8.2/§10.6):
            // present on Admin show/store/update only (relation anchored there);
            // index stays untouched and the block must never run per-row on lists.
            $this->mergeWhen($product->relationLoaded('crossSellProductsAdmin'), fn() => $this->crossSellBlock($product)),
        ];
    }

    /**
     * Admin cross-sell block: full saved management source (`cross_sell_ids`,
     * `saved_cross_sell_count`) plus the eligible-only list and count for the
     * CURRENT REQUEST CHANNEL. The count equals the eligible list length
     * (no separate COUNT query) and is NOT capped by the 8-item storefront
     * display limit.
     */
    private function crossSellBlock($product): array
    {
        $saved = $product->crossSellProductsAdmin;
        $eligible = $this->crossSellEligibleItems($product);

        return [
            'cross_sell_ids'            => $saved->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'saved_cross_sell_count'    => $saved->count(),
            'eligible_cross_sell_count' => $eligible->count(),
            'cross_sell_products'       => $eligible->map(fn ($p) => $this->crossSellAdminItem($p))->values()->all(),
            'storefront_cross_sell_limit' => ProductService::STOREFRONT_DISPLAY_LIMIT,
        ];
    }

    /**
     * Eligible cross-sell query on the same canonical sources as the
     * storefront: `Product::crossSellProducts()` (directional) + `active()`
     * + current channel rule (`FastShippingScope` for fast-shipping,
     * `applyChannelHomeFilter()` for home). Soft-deleted targets are
     * excluded by design; pivot order is preserved.
     */
    private function crossSellEligibleItems($product)
    {
        $query = $product->crossSellProducts()
            ->active()
            ->with(['media', 'categories'])
            ->where('products.id', '!=', $product->id);

        $this->applyChannelHomeFilter($query);

        return $query
            ->orderByPivot('sort_order')
            ->orderBy('product_cross_sells.id')
            ->get();
    }

    /**
     * Compact eligible Admin item (plan rev.3 §8.3): no `available`
     * or `unavailability_reasons` keys — the list is eligible-only.
     * `storefront_channels` stays an informational per-item map and never
     * drives the count.
     */
    private function crossSellAdminItem($product): array
    {
        $channelEnabled = (bool) config('channel.enabled', true);
        $isFast = (bool) $product->is_fast_shipping_available;
        // Items were picked through active() + the channel filter, so they
        // are available for the current channel by construction.
        $available = true;

        return [
            'id'       => (int) $product->id,
            'name'     => $product->getTranslation('name', app()->getLocale()),
            'slug'     => $product->slug,
            'price'    => $this->roundMoney($product->price),
            'thumbnail' => $product->getFirstMediaUrl('products'),
            'status'   => (bool) $product->status,
            'in_stock' => (bool) $product->in_stock,
            'deleted'  => $product->deleted_at !== null,
            'storefront_channels' => [
                'home'         => $available && (!$channelEnabled || !$isFast),
                'fast_shipping' => $available && (!$channelEnabled || $isFast),
            ],
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
                'locations' => $this->formatLocations($variant->productLocations),
                'attributes' => $this->getAttributeName($variant->attributeProducts),
            ];
        });
    }

    private function formatLocations($productLocations)
    {
        if (!$productLocations) {
            return [];
        }

        return $productLocations->map(function ($pl) {
            return [
                'id' => $pl->id,
                'location_id' => $pl->location_id,
                'warehouse_id' => $pl->warehouse_id,
                'quantity' => (float) $pl->quantity,
                'allocated_hint' => (float) ($pl->allocated_hint ?? 0),
                'location' => $pl->location ? [
                    'id' => $pl->location->id,
                    'code' => $pl->location->code,
                    'barcode' => $pl->location->barcode,
                    'name' => $pl->location->name,
                    'type' => $pl->location->type,
                ] : null,
                'warehouse' => $pl->warehouse ? [
                    'id' => $pl->warehouse->id,
                    'code' => $pl->warehouse->code,
                    'name' => $pl->warehouse->name,
                ] : null,
            ];
        })->values()->all();
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

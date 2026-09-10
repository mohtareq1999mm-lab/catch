<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\Banner\BannerResource;
use App\Http\Resources\Brand\BrandResource;
use App\Http\Resources\Slider\SliderResource;
use App\Traits\HasProductFilters;
use Marvel\Database\Models\Category;
use Marvel\Enums\DiscountType;
use Marvel\Http\Resources\TagResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    use HasProductFilters, ConvertsProductPrice;
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $convertedCurrentPrice = $this->convertCatalogPrice($this->current_price);

        return [
            'id'                     => $this->id,
            'name'                   => $this->getTranslation('name', app()->getLocale()),
            'slug'                   => $this->slug,
            'description'            => $this->getTranslation('description', app()->getLocale()),
            'price'                  => $this->convertCatalogPrice($this->price),
            'current_price'          => $convertedCurrentPrice,
            'currency'               => $this->effectiveCurrency(),
            'discount_type'          => $this->discount_type,
            'discount_amount'        => $this->formatDiscountAmount(),
            'start_date'             => $this->start_date,
            'end_date'               => $this->end_date,
            'sku'                    => $this->sku,
            'quantity'               => (int) $this->stock_quantity,
            'sold_quantity'          => (int) ($this->sold_quantity ?? 0),
            'in_stock'               => $this->in_stock,
            'product_type'           => $this->product_type,
            'item_type'              => $this->item_type,
            'height'                 => $this->height,
            'width'                  => $this->width,
            'length'                 => $this->length,
            'weight'                 => $this->weight,
            'has_flash_sale'         => $this->has_flash_sale,
            'has_discount'           => $this->has_discount,
            'is_fast_shipping_available' => (bool)$this->is_fast_shipping_available,
            $this->mergeWhen($this->has_discount, fn() => ['discount_valid' => (bool) $this->discount_active]),
            'discount_active' => (bool) $this->discount_active,
            'flash_sale_active' => (bool) $this->flash_sale_active,
            'categories'             => $this->whenLoaded('categories', fn() => $this->getFlatCategoryHierarchy()),
            "images"                 => [
                'thumbnail'  => $this->getFirstMediaUrl('products'),
                'original' => $this->getMediaImages('products'),
            ],
            'tags'                    => TagResource::collection($this->whenLoaded('tags')),
            "variants"                => $this->whenLoaded('variations', fn() => $this->getVariants()),
            'reviews'                 => ReviewResource::collection($this->whenLoaded('reviews')),
            $this->mergeWhen($this->relationLoaded('related_products'), fn() => ['related_products' => ProductMiniResource::collection($this->related_products)]),
            $this->mergeWhen(!request()->routeIs('general-product-show'), [
                'filters' => $this->getProductFilters($this->resource),
            ]),
        ];
    }




    private function getMediaImages($collection)
    {
        return $this->getmedia($collection)->slice(1)->map(function ($media) {
            return $media->getUrl();
        });
    }

    private function getVariants()
    {
        return $this->variations->map(function ($variant) {
            $convertedCurrentPrice = $this->convertCatalogPrice($variant->current_price);

            return [
                'id' => $variant->id,
                'price' => $this->convertCatalogPrice($variant->price),
                'current_price' => $convertedCurrentPrice,
                'quantity' => (int) $variant->quantity,
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

    private function formatDiscountAmount(): ?float
    {
        if ($this->discount_amount === null || $this->discount_amount === '') {
            return null;
        }

        if (in_array($this->discount_type, [DiscountType::FIXED_RATE, 'fixed'], true)) {
            return $this->convertCatalogPrice($this->discount_amount);
        }

        return $this->roundMoney($this->discount_amount);
    }

    private function roundMoney($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    private function getFlatCategoryHierarchy(): array
    {
        $all = collect();

        foreach ($this->categories as $category) {
            $all->push([
                'id'    => $category->id,
                'level' => $category->level,
                'name'  => $category->getTranslation('name', app()->getLocale()),
                'slug'  => $category->slug,
            ]);

            $current = $category;
            while ($current->parent) {
                $all->push([
                    'id'    => $current->parent->id,
                    'level' => $current->parent->level,
                    'name'  => $current->parent->getTranslation('name', app()->getLocale()),
                    'slug'  => $current->parent->slug,
                ]);
                $current = $current->parent;
            }
        }

        return $all->unique('id')->sortBy('level')->values()->all();
    }
}
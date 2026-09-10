<?php

namespace App\Http\Resources\Product;

use App\Traits\HasProductFilters;
use Marvel\Http\Resources\TagResource;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductMiniResource extends JsonResource
{
    use HasProductFilters, ConvertsProductPrice;
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */

    public function toArray($request): array
    {
        $convertedCurrentPrice = $this->convertCatalogPrice($this->current_price);

        return [
            'id' => $this->id,
            'name' => $this->getTranslation('name', app()->getLocale()),
            'slug' => $this->slug,
            'price' => $this->convertCatalogPrice($this->price),
            'has_variants' => $this->product_type !== 'simple' ? true : false,
            'item_type' => $this->item_type,
            'current_price' => $convertedCurrentPrice,
            'currency' => $this->effectiveCurrency(),
            'quantity' => (int) $this->stock_quantity,
            'in_stock'               =>(bool) $this->in_stock,
            'discount_active' => (bool) $this->discount_active,
            'flash_sale_active' => (bool) $this->flash_sale_active,
            'is_fast_shipping_available' =>(bool)$this->is_fast_shipping_available,
            'ratings' => round((float) ($this->reviews_avg_rating ?? 0), 2),
            'tags' => $this->relationLoaded('tags') ? TagResource::collection($this->tags) : TagResource::collection(collect()),
            'image' => [
                'thumbnail' => $this->getFirstMediaUrl('products'),
                'original' => $this->getMediaImages('products'),
            ],
        ];
    }




    private function roundMoney($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    private function getMediaImages($collection)
    {
        $media = $this->getMedia($collection);


        // Return all media URLs except the first (used as 'original')
        return $media->slice(1)
            ->map(function ($m) {
                return $m->getUrl();
            });
    }
}
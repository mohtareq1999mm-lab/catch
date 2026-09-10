<?php

namespace App\Http\Resources\Brand;

use App\Http\Resources\Product\ConvertsProductPrice;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandProductResource extends JsonResource
{
    use ConvertsProductPrice;

    public function toArray($request): array
    {
        // Centralized currency conversion — mirrors ProductMiniResource / ProductResource.
        // Do not read guest_currency directly; CurrencyService is the single source of truth.
        return [
            'id' => $this->id,
            'name' => $this->getTranslation('name', app()->getLocale()),
            'slug' => $this->slug,
            'price' => $this->convertCatalogPrice($this->price),
            'price_after_discount' => $this->convertCatalogPrice($this->current_price),
            // Backward-compat aliases — deprecated but preserved for older frontends.
            'current_price' => $this->convertCatalogPrice($this->current_price),
            'currency' => $this->effectiveCurrency(),
            'rating' => round((float) ($this->reviews_avg_rating ?? 0), 2),
            'image' => [
                'thumbnail' => $this->getFirstMediaUrl('products'),
            ],
        ];
    }
}
<?php

namespace App\Http\Resources\FlashSale;

use App\Http\Resources\Product\ProductMiniResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlashSaleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->getTranslation('title', app()->getLocale()),
            'discription' => $this?->getTranslation('description', app()->getLocale()),
            'slug' => $this->slug,
            'start_date' => $this->start_date,
            'image'                  =>  [
                'desktop' => $this->getFirstMediaUrl('flash-sales-desktop'),
                'mobile' => $this->getFirstMediaUrl('flash-sales-mobile'),
            ],
            'end_date' => $this->end_date,
            'products' => ProductMiniResource::collection($this->whenLoaded('products')),
        ];
    }
}

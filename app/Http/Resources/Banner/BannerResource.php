<?php

namespace App\Http\Resources\Banner;

use App\Http\Resources\Product\ProductMiniResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BannerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->getTranslation('title', app()->getLocale()),
            'slug'       => $this->slug,
            'description' => $this->getTranslation('description', app()->getLocale()),
            'image'       => [
                'desktop' => $this?->getFirstMediaUrl('banners-desktop'),
                'mobile' => $this?->getFirstMediaUrl('banners-mobile'),
            ],
            "status"   => (bool)$this->status,
            "products"    => $this->whenLoaded('products', function () {
                return ProductMiniResource::collection($this->products);
            }),
        ];
    }
}

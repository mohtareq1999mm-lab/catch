<?php

namespace App\Http\Resources\Slider;

use App\Http\Resources\Product\ProductMiniResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SliderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "title" => $this->getTranslation('title', app()->getLocale()),
            "slug" => $this->slug,
            "status" => (bool)$this->status,
            "image" => [
                "desktop" => $this->getFirstMediaUrl('sliders-desktop'),
                "mobile" => $this->getFirstMediaUrl('sliders-mobile'),
            ],
            "products" => $this->whenLoaded('products', function () {
                return ProductMiniResource::collection($this->products);
            }),
        ];
    }
}

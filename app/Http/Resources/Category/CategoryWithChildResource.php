<?php

namespace App\Http\Resources\Category;

use App\Http\Resources\Product\ProductMiniResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryWithChildResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'name'                 => $this->getTranslation('name', app()->getLocale()),
            'slug'                 => $this->slug,
            'image'                => [
                'desktop' =>   $this->getFirstMediaUrl('categories-desktop'),
                'mobile' =>   $this->getFirstMediaUrl('categories-mobile'),
            ],
            'products_count'       => $this->whenCounted('products'),
            $this->mergeWhen($this->getTranslation('details', app()->getLocale()), [
                'details' => $this->getTranslation('details', app()->getLocale()),
            ]),
            $this->mergeWhen($this->whenLoaded('children') && $this->children->isNotEmpty(), [
                'children' => CategoryHomeResource::collection($this->children),
            ]),
            $this->mergeWhen($this->whenLoaded('products') && $this->products->isNotEmpty(), [
                'products' => ProductMiniResource::collection($this->products),
            ])
        ];
    }
}

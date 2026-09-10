<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class BrandResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray(Request $request)
    {
        return [
            'id' => $this->id,
            'name' => request()->routeIs('brands.show') ? [
                'ar' => $this->getTranslation('name', 'ar'),
                'en' => $this->getTranslation('name', 'en'),
            ] : $this->getTranslation('name', app()->getLocale()),
            'slug' => $this->slug,
            'image' => [
                'desktop' => $this->getFirstMediaUrl('brands-desktop'),
                'mobile' => $this->getFirstMediaUrl('brands-mobile'),
            ],
            'details' => $this->getTranslation('details', app()->getLocale()),
            'status' => (bool) $this->status,
            $this->mergeWhen($this->relationLoaded('products'), [
                'products' => $this->products->map(fn($product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'status' => $product->status,
                    'image' => [
                        'thumbnail' => $product->getFirstMediaUrl('products'),
                    ],
                ]),
            ]),
        ];
    }
}

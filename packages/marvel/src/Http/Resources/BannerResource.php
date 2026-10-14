<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class BannerResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'title'       => request()->routeIs('banners.show') ? [
                'ar' => $this->getTranslation('title', 'ar'),
                'en' => $this->getTranslation('title', 'en'),
            ] : $this->getTranslation('title', app()->getLocale()),
            'slug'        => $this->slug,
            'description' => $this->description,
            'image'       => [
                "desktop" => $this->when($this->getFirstMediaUrl('banners-desktop'), $this->getFirstMediaUrl('banners-desktop')),
                "mobile" => $this->when($this->getFirstMediaUrl('banners-mobile'), $this->getFirstMediaUrl('banners-mobile')),
            ],
            "status"   => (bool)$this->status,
            "products"    => $this->whenLoaded('products', function () {
                return ProductResource::collection($this->products);
            }),
        ];

    }


}

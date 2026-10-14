<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;
use Marvel\Helper\ResourceHelpers;

class TagResource extends Resource
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
            'id'                   => $this->id,
            'name'                 => request()->routeIs('tags.show') ? $this->name : $this->getTranslation('name', app()->getLocale()),
            'slug'                 => $this->slug,
            'image'                => $this->getFirstMediaUrl('tags')?? null,
            'icon'                 => $this->getFirstMediaUrl('tags') ?? null,
            $this->mergeWhen($this->relationLoaded('products'), [
                'products' => $this->products->map(fn ($product) => [
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
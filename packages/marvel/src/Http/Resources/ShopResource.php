<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class ShopResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
         $excludedRoutes = [
            'general-shop-index',
            'shops.index',
            'shops.update',
            'shops.store',
        ];
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'cover_image' => $this->getFirstMediaUrl('shop-image'),
            'logo' => $this->getFirstMediaUrl('shop-logo'),
            'status' => (bool) $this->status,
            'address' =>  collect($this->address)->map(function ($addr) {
                return [
                    'city'           => $addr['city'][app()->getLocale()] ?? null,
                    'state'          => $addr['state'][app()->getLocale()] ?? null,
                    'country'        => $addr['country'][app()->getLocale()] ?? null,
                    'street_address' => $addr['street_address'][app()->getLocale()] ?? null,
                ];
            }),
            'created_at' => $this->created_at,
            $this->mergeWhen(
                !request()->routeIs($excludedRoutes),
                [
                    'categories' => $this->whenLoaded('categories', CategoryResource::collection($this->categories)),
                ]
            )
        ];
    }
}

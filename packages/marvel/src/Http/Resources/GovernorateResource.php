<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GovernorateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'country_id' => $this->country_id,
            'name' => request()->routeIs('governorates.show') ? [
                'ar' => $this->getTranslation('name', 'ar'),
                'en' => $this->getTranslation('name', 'en'),
            ] : $this->getTranslation('name', app()->getLocale()),
            'status' => (bool) $this->status,
            'is_fast_shipping_enabled' => (bool) $this->is_fast_shipping_enabled,
            'country' =>  CountryResource::make($this->whenLoaded('country')),
            'cities' => CityResource::collection($this->whenLoaded('cities')),
            'shipping_price' =>  ShippingPriceResource::make($this->whenLoaded('shippingPrice')),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}

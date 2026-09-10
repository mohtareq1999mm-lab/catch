<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CountryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => request()->routeIs('countries.show') ? [
                'ar' => $this->getTranslation('name', 'ar'),
                'en' => $this->getTranslation('name', 'en'),
            ] : $this->getTranslation('name', app()->getLocale()),
            'phone_code' => $this->phone_code,
            'status' => (bool) $this->status,
            'governorates' => GovernorateResource::collection($this->whenLoaded('governorates')),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}

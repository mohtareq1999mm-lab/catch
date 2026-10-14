<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'governorate_id' => $this->governorate_id,
            'name' => request()->routeIs('cities.show') ? [
                'ar' => $this->getTranslation('name', 'ar'),
                'en' => $this->getTranslation('name', 'en'),
            ] : $this->getTranslation('name', app()->getLocale()),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}

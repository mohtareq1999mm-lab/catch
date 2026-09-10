<?php

namespace App\Http\Resources\Currency;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrencyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->getTranslation('name', app()->getLocale()),
            'symbol' => $this->getTranslation('symbol', app()->getLocale()),
            'country_name' => $this->getTranslation('country_name', app()->getLocale()),
            'numeric_code' => $this->numeric_code,
            'decimal_places' => (int) $this->decimal_places,
            'icon' => $this->icon,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'is_base' => $this->isBaseCurrency(),
            'is_catalog' => $this->isCatalogCurrency(),
            'effective_rate' => $this->effectiveRate(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

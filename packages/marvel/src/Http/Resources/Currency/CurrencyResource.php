<?php

namespace Marvel\Http\Resources\Currency;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrencyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->getTranslations('name'),
            'symbol' => $this->getTranslations('symbol'),
            'country_name' => $this->getTranslations('country_name'),
            'numeric_code' => $this->numeric_code,
            'decimal_places' => (int) $this->decimal_places,
            'icon' => $this->icon,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'is_base' => $this->isBaseCurrency(),
            'is_catalog' => $this->isCatalogCurrency(),
            'effective_rate' => $this->effectiveRate(),
            'rate_mode' => $this->rate_mode?->value,
            'manual_rate' => $this->manual_rate,
            'provider_rate' => $this->provider_rate,
            'provider' => $this->provider,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'provider_rate_at' => $this->provider_rate_at?->toIso8601String(),
            'effective_rate_updated_at' => $this->effective_rate_updated_at?->toIso8601String(),
            'is_rate_stale' => $this->isRateStale(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

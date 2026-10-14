<?php

namespace Marvel\Http\Resources\Currency;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrencyRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'currency' => $this->whenLoaded('currency', fn () => [
                'id' => $this->currency->id,
                'code' => $this->currency->code,
                'name' => $this->currency->getTranslations('name'),
                'symbol' => $this->currency->getTranslations('symbol'),
            ]),
            'exchange_rate' => $this->exchange_rate,
            'source' => $this->source?->value,
            'provider' => $this->provider,
            'effective_date' => $this->effective_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

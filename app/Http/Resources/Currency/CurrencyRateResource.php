<?php

namespace App\Http\Resources\Currency;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrencyRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'currency_id' => $this->currency_id,
            'exchange_rate' => $this->exchange_rate,
            'effective_date' => $this->effective_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources\Settlement;

use Illuminate\Http\Resources\Json\JsonResource;

class CodSettlementStatementResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'statement_key' => $this->statement_key,
            'provider_code' => $this->provider_code,
            'mode' => $this->mode,
            'account_code' => $this->account_code,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'currency_code' => $this->currency_code,
            'decimal_places' => (int) $this->decimal_places,
            'expected_minor' => (int) $this->expected_minor,
            'received_minor' => (int) $this->received_minor,
            'variance_minor' => (int) $this->variance_minor,
            'lines' => $this->whenLoaded(
                'lines',
                fn () => CodSettlementLineResource::collection($this->lines)
            ),
        ];
    }
}

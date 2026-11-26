<?php

namespace App\Http\Resources\Settlement;

use App\Enums\CodSettlementStatementStatus;
use Illuminate\Http\Resources\Json\JsonResource;

class CodSettlementStatementResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'statement_key' => $this->statement_key,
            'file_hash' => $this->file_hash,
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
            'status' => $this->status ?? CodSettlementStatementStatus::OPEN->value,
            'closed_by' => $this->closed_by !== null ? (int) $this->closed_by : null,
            'closed_at' => $this->closed_at?->toISOString(),
            'close_reason' => $this->close_reason,
            'lines' => $this->whenLoaded(
                'lines',
                fn () => CodSettlementLineResource::collection($this->lines)
            ),
        ];
    }
}

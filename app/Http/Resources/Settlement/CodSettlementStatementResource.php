<?php

namespace App\Http\Resources\Settlement;

use App\Enums\CodStatementAcceptanceStatus;
use App\Enums\CodSettlementStatementStatus;
use App\Enums\CodStatementType;
use Illuminate\Http\Resources\Json\JsonResource;

class CodSettlementStatementResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'statement_key' => $this->statement_key,
            'file_hash' => $this->file_hash,
            'statement_type' => $this->statement_type ?? CodStatementType::ORIGINAL->value,
            'supersedes_statement_id' => $this->supersedes_statement_id !== null
                ? (int) $this->supersedes_statement_id
                : null,
            'acceptance_status' => $this->acceptance_status ?? CodStatementAcceptanceStatus::PENDING->value,
            'reviewed_by' => $this->reviewed_by !== null ? (int) $this->reviewed_by : null,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'review_reason' => $this->review_reason,
            'superseded_at' => $this->superseded_at?->toISOString(),
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

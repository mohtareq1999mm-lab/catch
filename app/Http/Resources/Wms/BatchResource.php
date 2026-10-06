<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_number' => $this->batch_number,
            'warehouse_id' => $this->warehouse_id,
            'status' => $this->status,
            'type' => $this->type,
            'assigned_to' => $this->assigned_to,
            'total_items' => (int) $this->total_items,
            'picked_items' => (int) $this->picked_items,
            'progress' => $this->progressPercentage(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackingTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fulfillment_id' => $this->fulfillment_id,
            'packing_station_id' => $this->packing_station_id,
            'assigned_to' => $this->assigned_to,
            'status' => $this->status,
            'weight' => $this->weight !== null ? (float) $this->weight : null,
            'packed_at' => $this->packed_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

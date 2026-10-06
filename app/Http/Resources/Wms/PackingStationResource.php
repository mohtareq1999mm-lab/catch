<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackingStationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status,
            'assigned_to' => $this->assigned_to,
            'daily_capacity' => $this->daily_capacity !== null ? (int) $this->daily_capacity : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

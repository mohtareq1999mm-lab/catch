<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackingTaskDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fulfillment_id' => $this->fulfillment_id,
            'packing_station_id' => $this->packing_station_id,
            'station' => $this->whenLoaded('packingStation', fn () => [
                'id' => $this->packingStation->id,
                'code' => $this->packingStation->code,
                'name' => $this->packingStation->name,
                'warehouse_id' => $this->packingStation->warehouse_id,
            ]),
            'assigned_to' => $this->assigned_to,
            'status' => $this->status,
            'weight' => $this->weight !== null ? (float) $this->weight : null,
            'dimensions' => $this->dimensions,
            'package_materials' => $this->package_materials,
            'notes' => $this->notes,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'packed_at' => $this->packed_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

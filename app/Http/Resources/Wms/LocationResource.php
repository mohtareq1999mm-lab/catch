<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'parent_id' => $this->parent_id,
            'code' => $this->code,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'type' => $this->type,
            'status' => $this->status,
            'priority' => $this->priority !== null ? (int) $this->priority : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

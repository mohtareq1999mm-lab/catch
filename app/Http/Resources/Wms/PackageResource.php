<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fulfillment_id' => $this->fulfillment_id,
            'packing_task_id' => $this->packing_task_id,
            'package_number' => $this->package_number,
            'barcode' => $this->barcode,
            'status' => $this->status,
            'weight' => $this->weight !== null ? (float) $this->weight : null,
            'item_count' => $this->whenCounted('items', fn () => (int) $this->items_count),
            'sealed_at' => $this->sealed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

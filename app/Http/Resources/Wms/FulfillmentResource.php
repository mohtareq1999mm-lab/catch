<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FulfillmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fulfillment_number' => $this->fulfillment_number,
            'order_id' => $this->order_id,
            'warehouse_id' => $this->warehouse_id,
            'status' => $this->status,
            'priority' => $this->priority,
            'assigned_to' => $this->assigned_to,
            'items_count' => (int) ($this->items_count ?? 0),
            'shipments_count' => (int) ($this->shipments_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

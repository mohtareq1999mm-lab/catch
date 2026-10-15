<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseWorkflowVersionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'version' => (int) $this->id,
            'picking_enabled' => (bool) $this->picking_enabled,
            'packing_enabled' => (bool) $this->packing_enabled,
            'shipping_enabled' => (bool) $this->shipping_enabled,
            'order_received_enabled' => (bool) $this->order_received_enabled,
            'created_by' => $this->created_by,
            'published_at' => $this->published_at,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}

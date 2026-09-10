<?php

namespace App\Http\Resources\Shipment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'order_id' => $this->order_id,
            'tracking_number' => $this->tracking_number,
            'courier' => $this->courier,
            'status' => $this->status,
            'shipping_method' => $this->shipping_method,
            'shipping_cost' => (float) $this->shipping_cost,
            'currency' => $this->currency,
            'origin_address' => $this->origin_address,
            'destination_address' => $this->destination_address,
            'items' => $this->items,
            'total_weight' => (float) $this->total_weight,
            'weight_unit' => $this->weight_unit,
            'notes' => $this->notes,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'estimated_delivery_at' => $this->estimated_delivery_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'order_id' => $this->order_id,
            'fulfillment_id' => $this->fulfillment_id,
            'packing_task_id' => $this->packing_task_id,
            'tracking_number' => $this->tracking_number,
            'courier' => $this->courier,
            'status' => $this->status,
            'shipping_method' => $this->shipping_method,
            'shipping_cost' => $this->shipping_cost !== null ? (float) $this->shipping_cost : null,
            'currency' => $this->currency,
            'total_weight' => $this->total_weight !== null ? (float) $this->total_weight : null,
            'origin_address' => $this->origin_address,
            'destination_address' => $this->destination_address,
            'notes' => $this->notes,
            'cancel_reason' => $this->cancel_reason,
            'cancel_source' => $this->cancel_source,
            'cancelled_by' => $this->cancelled_by,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'estimated_delivery_at' => $this->estimated_delivery_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

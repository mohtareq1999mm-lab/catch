<?php

namespace App\Http\Resources\Wms;

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
            'fulfillment_id' => $this->fulfillment_id,
            'tracking_number' => $this->tracking_number,
            'courier' => $this->courier,
            'status' => $this->status,
            'shipping_method' => $this->shipping_method,
            'total_weight' => $this->total_weight !== null ? (float) $this->total_weight : null,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FulfillmentItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fulfillment_id' => $this->fulfillment_id,
            'order_item_id' => $this->order_item_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'product_location_id' => $this->product_location_id,
            'quantity' => $this->quantity,
            'quantity_picked' => $this->quantity_picked,
            'status' => $this->status,
            'picked_at' => $this->picked_at?->toIso8601String(),
            'packed_at' => $this->packed_at?->toIso8601String(),
        ];
    }
}

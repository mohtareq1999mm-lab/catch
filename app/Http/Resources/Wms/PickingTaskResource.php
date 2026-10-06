<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PickingTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'fulfillment_item_id' => $this->fulfillment_item_id,
            'order_id' => $this->order_id,
            'product_location_id' => $this->product_location_id,
            'quantity_to_pick' => $this->quantity_to_pick,
            'quantity_picked' => $this->quantity_picked,
            'remaining' => (float) $this->quantity_to_pick - (float) $this->quantity_picked,
            'status' => $this->status,
            'sequence' => $this->sequence !== null ? (int) $this->sequence : null,
            'claimed_by' => $this->claimed_by,
            'claimed_at' => $this->claimed_at?->toIso8601String(),
            'claim_expires_at' => $this->claim_expires_at?->toIso8601String(),
            'picked_at' => $this->picked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

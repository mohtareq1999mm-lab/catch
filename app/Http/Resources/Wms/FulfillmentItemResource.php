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
            'status' => $this->resolveItemStatus(),
            'picked_at' => $this->picked_at?->toIso8601String(),
            'packed_at' => $this->packed_at?->toIso8601String(),
            // Current placement at a glance: the Admin frontend identifies
            // the shelf from this block alone after assign-placement (no
            // extra lookups). Additive only — legacy id keys preserved.
            'placement' => $this->whenLoaded('productLocation', function () {
                if (!$this->productLocation) {
                    return null;
                }
                $loc = $this->productLocation->relationLoaded('location')
                    ? $this->productLocation->location
                    : null;

                return [
                    'id' => $this->productLocation->id,
                    'location_id' => $this->productLocation->location_id,
                    'warehouse_id' => $this->productLocation->warehouse_id,
                    // SKU identity (NULL = simple row): the shelf block alone
                    // must identify which SKU sits in the bin.
                    'product_variant_id' => $this->productLocation->product_variant_id !== null ? (int) $this->productLocation->product_variant_id : null,
                    'quantity' => (float) $this->productLocation->quantity,
                    'allocated_hint' => (float) $this->productLocation->allocated_hint,
                    'code' => $loc?->code,
                    'name' => $loc?->name,
                    'barcode' => $loc?->barcode,
                ];
            }),
        ];
    }

    private function resolveItemStatus(): string
    {
        if ($this->status !== null && $this->status !== 'pending') {
            return $this->status;
        }

        if ($this->packed_at !== null) {
            return 'packed';
        }

        if ((float) $this->quantity_picked >= (float) $this->quantity && (float) $this->quantity > 0) {
            return 'picked';
        }

        if ((float) $this->quantity_picked > 0) {
            return 'picking';
        }

        return $this->status ?? 'pending';
    }
}

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
            // Live placements in this location (multi-location: one SKU may
            // sit in many locations; one location hosts one SKU).
            // Present only when eager-loaded (detail views) — list responses
            // stay light. Additive only.
            'placements' => $this->whenLoaded('productLocations', fn () => $this->productLocations->map(fn ($p) => [
                'id' => $p->id,
                'product_id' => $p->product_id,
                'product_sku' => $p->relationLoaded('product') ? $p->product?->sku : null,
                'product_variant_id' => $p->product_variant_id !== null ? (int) $p->product_variant_id : null,
                'quantity' => (float) $p->quantity,
                'allocated_hint' => (float) $p->allocated_hint,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

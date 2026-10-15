<?php

namespace App\Http\Resources\Wms;

use App\Http\Resources\Wms\Traits\FormatsWmsProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackingTaskDetailResource extends JsonResource
{
    use FormatsWmsProductVariant;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fulfillment_id' => $this->fulfillment_id,
            'packing_station_id' => $this->packing_station_id,
            'station' => $this->whenLoaded('packingStation', fn () => [
                'id' => $this->packingStation->id,
                'code' => $this->packingStation->code,
                'name' => $this->packingStation->name,
                'warehouse_id' => $this->packingStation->warehouse_id,
            ]),
            'assigned_to' => $this->assigned_to,
            'status' => $this->status,
            'weight' => $this->weight !== null ? (float) $this->weight : null,
            'dimensions' => $this->dimensions,
            'package_materials' => $this->package_materials,
            'notes' => $this->notes,
            'items' => $this->whenLoaded('fulfillment', function () {
                if (!$this->fulfillment || !$this->fulfillment->relationLoaded('items')) {
                    return null;
                }

                return $this->fulfillment->items->map(fn ($item) => [
                    'id' => $item->id,
                    'order_item_id' => $item->order_item_id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'product_location_id' => $item->product_location_id,
                    'quantity' => (float) $item->quantity,
                    'quantity_picked' => (float) $item->quantity_picked,
                    'status' => $item->status,
                    'product' => $item->relationLoaded('product') ? $this->formatProduct($item->product) : null,
                    'variant' => $item->relationLoaded('productVariant') ? $this->formatVariant($item->productVariant) : null,
                    'location' => $item->relationLoaded('productLocation') && $item->productLocation ? [
                        'id' => $item->productLocation->id,
                        'code' => $item->productLocation->location?->code,
                        'name' => $item->productLocation->location?->name,
                        'barcode' => $item->productLocation->location?->barcode,
                    ] : null,
                ])->all();
            }),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'packed_at' => $this->packed_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources\Wms;

use App\Http\Resources\Wms\Traits\FormatsWmsProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackageDetailResource extends JsonResource
{
    use FormatsWmsProductVariant;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fulfillment_id' => $this->fulfillment_id,
            'packing_task_id' => $this->packing_task_id,
            'package_number' => $this->package_number,
            'barcode' => $this->barcode,
            'status' => $this->status,
            'weight' => $this->weight !== null ? (float) $this->weight : null,
            'dimensions' => $this->dimensions,
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(function ($item) {
                $fItem = $item->relationLoaded('fulfillmentItem') ? $item->fulfillmentItem : null;

                return [
                    'id' => $item->id,
                    'fulfillment_item_id' => $item->fulfillment_item_id,
                    'order_item_id' => $item->order_item_id,
                    'quantity' => (float) $item->quantity,
                    'product' => ($fItem && $fItem->relationLoaded('product'))
                        ? $this->formatProduct($fItem->product)
                        : null,
                    'variant' => ($fItem && $fItem->relationLoaded('productVariant'))
                        ? $this->formatVariant($fItem->productVariant)
                        : null,
                ];
            })->all()),
            'sealed_at' => $this->sealed_at?->toIso8601String(),
            'handed_off_at' => $this->handed_off_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

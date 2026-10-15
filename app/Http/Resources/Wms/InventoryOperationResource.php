<?php

namespace App\Http\Resources\Wms;

use App\Http\Resources\Wms\Traits\FormatsWmsProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryOperationResource extends JsonResource
{
    use FormatsWmsProductVariant;

    /**
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->idempotency_key,
            'operation' => $this->operation,
            'status' => $this->status,
            'product_id' => (int) $this->product_id,
            'product_variant_id' => $this->product_variant_id !== null ? (int) $this->product_variant_id : null,
            'warehouse_id' => $this->warehouse_id !== null ? (int) $this->warehouse_id : null,
            'source_location_id' => $this->source_location_id !== null ? (int) $this->source_location_id : null,
            'destination_location_id' => $this->destination_location_id !== null ? (int) $this->destination_location_id : null,
            'quantity' => (float) $this->quantity,
            'reference' => $this->reference,
            'product' => $this->whenLoaded('product', fn () => $this->formatProduct($this->product)),
            'variant' => $this->whenLoaded('productVariant', fn () => $this->formatVariant($this->productVariant)),
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
                'code' => $this->warehouse->code,
            ] : null),
            'source_location' => $this->whenLoaded('sourceLocation', fn () => $this->sourceLocation ? [
                'id' => $this->sourceLocation->id,
                'code' => $this->sourceLocation->code,
                'name' => $this->sourceLocation->name,
            ] : null),
            'destination_location' => $this->whenLoaded('destinationLocation', fn () => $this->destinationLocation ? [
                'id' => $this->destinationLocation->id,
                'code' => $this->destinationLocation->code,
                'name' => $this->destinationLocation->name,
            ] : null),
            'result' => $this->result,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

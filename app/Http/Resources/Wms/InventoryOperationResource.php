<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryOperationResource extends JsonResource
{
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
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'product_type' => $this->product->product_type ?? null,
            ] : null),
            'variant' => $this->whenLoaded('productVariant', fn () => $this->productVariant ? [
                'id' => $this->productVariant->id,
                'sku' => $this->productVariant->sku,
                'price' => (float) ($this->productVariant->price ?? 0),
                'stock_quantity' => (int) ($this->productVariant->stock_quantity ?? 0),
                'attributes' => $this->variantAttributes($this->productVariant),
            ] : null),
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

    private function variantAttributes(mixed $variant): array
    {
        if (!$variant || !$variant->relationLoaded('attributeProducts')) {
            return [];
        }

        $out = [];
        foreach ($variant->attributeProducts as $ap) {
            $av = $ap->attributeValue ?? null;
            $attr = $av?->attribute ?? null;
            if ($av) {
                $out[] = [
                    'attribute' => $attr?->name ?? $attr?->slug ?? null,
                    'value' => $av->value ?? $av->name ?? null,
                ];
            }
        }

        return $out;
    }
}

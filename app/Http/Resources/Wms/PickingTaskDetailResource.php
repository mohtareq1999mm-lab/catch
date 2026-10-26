<?php

namespace App\Http\Resources\Wms;

use App\Http\Resources\Wms\Traits\FormatsWmsProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * P9-4.1: bounded picking-task detail. scan_log is staff-facing audit
 * (actor ids + scans) — included because every P9-4 read requires an
 * authenticated scoped operator. No `allowed_actions` (P9-9 owns that).
 */
class PickingTaskDetailResource extends JsonResource
{
    use FormatsWmsProductVariant;

    public function toArray(Request $request): array
    {
        $item = $this->relationLoaded('fulfillmentItem')
            ? $this->fulfillmentItem
            : null;

        $formattedItem = $this->formatItem($item);

        return array_merge((new PickingTaskResource($this->resource))->toArray($request), [
            'fulfillment' => $this->when(
                $this->relationLoaded('fulfillmentItem')
                    && $this->fulfillmentItem
                    && $this->fulfillmentItem->relationLoaded('fulfillment')
                    && $this->fulfillmentItem->fulfillment,
                fn () => [
                    'id' => $this->fulfillmentItem->fulfillment->id,
                    'fulfillment_number' => $this->fulfillmentItem->fulfillment->fulfillment_number,
                    'warehouse_id' => $this->fulfillmentItem->fulfillment->warehouse_id,
                    'status' => $this->fulfillmentItem->fulfillment->status,
                    'assigned_to' => $this->fulfillmentItem->fulfillment->assigned_to,
                ]
            ),
            'location' => $this->whenLoaded('productLocation', function () {
                if (!$this->productLocation) {
                    return null;
                }
                // §B location clarity: the operator identifies the shelf from
                // this block alone (no extra lookups). Additive only — the
                // legacy id keys are preserved for existing callers.
                $loc = $this->productLocation->relationLoaded('location')
                    ? $this->productLocation->location
                    : null;

                return [
                    'id' => $this->productLocation->id,
                    'location_id' => $this->productLocation->location_id,
                    'warehouse_id' => $this->productLocation->warehouse_id,
                    'code' => $loc?->code,
                    'name' => $loc?->name,
                    'barcode' => $loc?->barcode,
                    'type' => $loc?->type,
                    'status' => $loc?->status,
                ];
            }),
            'op_seq' => $this->op_seq !== null ? (int) $this->op_seq : 0,
            'scan_log' => $this->scan_log ?? [],
            'notes' => $this->notes,
            'items' => $formattedItem !== null ? [$formattedItem] : [],
        ]);
    }

    private function formatItem($item): ?array
    {
        if (!$item) {
            return null;
        }

        $productLocation = $this->relationLoaded('productLocation') && $this->productLocation
            ? $this->productLocation
            : ($item->relationLoaded('productLocation') ? $item->productLocation : null);

        $loc = $productLocation && $productLocation->relationLoaded('location')
            ? $productLocation->location
            : null;

        return [
            'id' => (int) $item->id,
            'order_item_id' => $item->order_item_id !== null ? (int) $item->order_item_id : null,
            'product_id' => (int) $item->product_id,
            'product_variant_id' => $item->product_variant_id !== null ? (int) $item->product_variant_id : null,
            'product_location_id' => $item->product_location_id !== null ? (int) $item->product_location_id : null,
            'quantity' => (float) $item->quantity,
            'quantity_picked' => (float) $item->quantity_picked,
            'status' => $this->resolveItemStatus($item),
            'product' => $item->relationLoaded('product')
                ? $this->formatProduct($item->product)
                : null,
            'variant' => $item->relationLoaded('productVariant')
                ? $this->formatVariant($item->productVariant)
                : null,
            'location' => $productLocation ? [
                'id' => (int) $productLocation->id,
                'code' => $loc?->code,
                'name' => $loc?->name,
                'barcode' => $loc?->barcode,
            ] : null,
        ];
    }

    private function resolveItemStatus($item): string
    {
        if ($item->status !== null && $item->status !== 'pending') {
            return $item->status;
        }

        if ($this->status === 'picked' || $this->picked_at !== null || $item->picked_at !== null) {
            return 'picked';
        }

        if ((float) $item->quantity_picked >= (float) $item->quantity && (float) $item->quantity > 0) {
            return 'picked';
        }

        if ((float) $item->quantity_picked > 0 || $this->status === 'picking') {
            return 'picking';
        }

        return $item->status ?? ($this->status ?? 'pending');
    }
}

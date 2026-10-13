<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * P9-4.1: bounded picking-task detail. scan_log is staff-facing audit
 * (actor ids + scans) — included because every P9-4 read requires an
 * authenticated scoped operator. No `allowed_actions` (P9-9 owns that).
 */
class PickingTaskDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
        ]);
    }
}

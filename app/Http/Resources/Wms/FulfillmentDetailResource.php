<?php

namespace App\Http\Resources\Wms;

use App\Http\Resources\Shipment\ShipmentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * P9-3: bounded fulfillment detail. Relations are eager-loaded by the
 * controller (no N+1); every field comes from the model or an existing
 * relation. No `allowed_actions` key yet — P9-9 owns that contract.
 */
class FulfillmentDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fulfillment_number' => $this->fulfillment_number,
            'order_id' => $this->order_id,
            'order' => $this->whenLoaded('order', fn () => [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'status' => $this->order->status,
            ]),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
                'status' => $this->warehouse->status,
            ]),
            'status' => $this->status,
            'priority' => $this->priority,
            'assigned_to' => $this->assigned_to,
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser ? [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'order_item_id' => $item->order_item_id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product_location_id' => $item->product_location_id,
                'quantity' => $item->quantity,
                'quantity_picked' => $item->quantity_picked,
                'status' => $item->status,
            ])->all()),
            'picking_tasks' => $this->when(
                $this->relationLoaded('pickingTasksManual'),
                fn () => $this->pickingTasksManual->map(fn ($task) => [
                    'id' => $task->id,
                    'fulfillment_item_id' => $task->fulfillment_item_id,
                    'batch_id' => $task->batch_id,
                    'status' => $task->status,
                    'claimed_by' => $task->claimed_by,
                    'quantity_to_pick' => $task->quantity_to_pick,
                    'quantity_picked' => $task->quantity_picked,
                ])->all()
            ),
            'packing_tasks' => $this->whenLoaded('packingTasks', fn () => $this->packingTasks->map(fn ($task) => [
                'id' => $task->id,
                'status' => $task->status,
                'packing_station_id' => $task->packing_station_id,
                'assigned_to' => $task->assigned_to,
            ])->all()),
            'packages' => $this->when(
                $this->relationLoaded('packagesManual'),
                fn () => $this->packagesManual->map(fn ($package) => [
                    'id' => $package->id,
                    'packing_task_id' => $package->packing_task_id,
                    'status' => $package->status,
                    'package_number' => $package->package_number,
                ])->all()
            ),
            'shipments' => ShipmentResource::collection($this->whenLoaded('shipments')),
            'cancelled_by' => $this->cancelled_by,
            'cancel_source' => $this->cancel_source,
            'notes' => $this->notes,
            'picking_started_at' => $this->picking_started_at?->toIso8601String(),
            'picking_completed_at' => $this->picking_completed_at?->toIso8601String(),
            'packing_started_at' => $this->packing_started_at?->toIso8601String(),
            'packing_completed_at' => $this->packing_completed_at?->toIso8601String(),
            'ready_to_ship_at' => $this->ready_to_ship_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

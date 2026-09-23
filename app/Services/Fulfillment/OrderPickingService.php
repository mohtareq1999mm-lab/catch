<?php

namespace App\Services\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\PickingTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8: single-order picking — standalone tasks (batch_id NULL) on the one
 * task model. Confirmation itself lives in PickingExecutionService (shared).
 */
class OrderPickingService
{
    /**
     * Create one pending task per unpicked fulfillment item that has a
     * location allocation. Idempotent: existing open tasks are reused.
     *
     * @return PickingTask[]
     */
    public function createTasksForFulfillment(Fulfillment $fulfillment): array
    {
        return DB::transaction(function () use ($fulfillment) {
            $tasks = [];

            foreach ($fulfillment->items()->get() as $item) {
                if ($item->product_location_id === null) {
                    continue; // manual assignment required (allocation fallback)
                }
                $remaining = (float) $item->quantity - (float) $item->quantity_picked;
                if ($remaining <= 0) {
                    continue;
                }

                // Phase 9: an item with ANY open task (order OR batch) must not
                // gain a second one — prevents double-picking across flows.
                $existing = PickingTask::where('fulfillment_item_id', $item->id)
                    ->whereIn('status', ['pending', 'assigned', 'picking'])
                    ->first();
                if ($existing) {
                    $tasks[] = $existing;
                    continue;
                }

                $tasks[] = PickingTask::create([
                    'batch_id' => null,
                    'fulfillment_item_id' => $item->id,
                    'product_location_id' => $item->product_location_id,
                    'order_id' => $fulfillment->order_id,
                    'order_item_id' => $item->order_item_id,
                    'quantity_to_pick' => $remaining,
                    'quantity_picked' => 0,
                    'status' => 'pending',
                    'sequence' => count($tasks) + 1,
                ]);
            }

            Log::info('Order picking tasks created', [
                'fulfillment_id' => $fulfillment->id,
                'tasks' => count($tasks),
            ]);

            return $tasks;
        });
    }
}

<?php

namespace App\Services\Fulfillment;

use App\Models\Fulfillment\FulfillmentBatch;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BatchPickingService
{
    public function __construct(
        private FulfillmentTransition $transitions,
    ) {}
    /**
     * Create a batch from multiple fulfillments (wave picking)
     */
    public function createBatchFromFulfillments(
        Collection $fulfillments,
        ?int $warehouseId = null,
        string $type = 'wave'
    ): FulfillmentBatch {
        return DB::transaction(function () use ($fulfillments, $warehouseId, $type) {
            // Get warehouse
            if (!$warehouseId) {
                $warehouse = Warehouse::default()->firstOrFail();
                $warehouseId = $warehouse->id;
            }

            // Generate batch number
            $batchNumber = $this->generateBatchNumber();

            // Create batch
            $batch = FulfillmentBatch::create([
                'warehouse_id' => $warehouseId,
                'batch_number' => $batchNumber,
                'status' => 'pending',
                'type' => $type,
                'total_items' => 0,
                'picked_items' => 0,
            ]);

            // Collect all fulfillment items across all fulfillments
            $allItems = collect();
            foreach ($fulfillments as $fulfillment) {
                $items = $fulfillment->items()
                    ->where('status', 'pending')
                    ->whereNotNull('product_location_id')
                    ->get();
                $allItems = $allItems->concat($items);
            }

            // Group by location for optimized picking route
            $itemsByLocation = $allItems->groupBy('product_location_id');

            // Create picking tasks ordered by location priority
            $sequence = 1;
            $totalItems = 0;

            foreach ($itemsByLocation as $locationId => $items) {
                foreach ($items as $item) {
                    PickingTask::create([
                        'batch_id' => $batch->id,
                        'fulfillment_item_id' => $item->id,
                        'product_location_id' => $locationId,
                        'quantity_to_pick' => $item->quantity,
                        'quantity_picked' => 0,
                        'status' => 'pending',
                        'sequence' => $sequence++,
                    ]);

                    $totalItems++;
                }
            }

            // Update batch totals
            $batch->update(['total_items' => $totalItems]);

            // Update fulfillments status via the single transition owner.
            foreach ($fulfillments as $fulfillment) {
                $this->transitions->transition($fulfillment, 'picking', ['reason' => 'batch_created']);
            }

            Log::info('Batch picking created', [
                'batch_id' => $batch->id,
                'batch_number' => $batchNumber,
                'warehouse_id' => $warehouseId,
                'fulfillments_count' => $fulfillments->count(),
                'total_tasks' => $totalItems,
            ]);

            return $batch->load('pickingTasks.productLocation.location');
        });
    }

    /**
     * Assign batch to user
     */
    public function assignBatch(FulfillmentBatch $batch, int $userId): FulfillmentBatch
    {
        if (!in_array($batch->status, ['pending', 'assigned'])) {
            throw new \Exception(
                "Cannot assign batch in status: {$batch->status}"
            );
        }

        $batch->update([
            'assigned_to' => $userId,
            'status' => 'assigned',
        ]);

        Log::info('Batch assigned to user', [
            'batch_id' => $batch->id,
            'user_id' => $userId,
        ]);

        return $batch->fresh();
    }

    /**
     * Start picking batch
     */
    public function startPicking(FulfillmentBatch $batch): FulfillmentBatch
    {
        if ($batch->status !== 'assigned') {
            throw new \Exception(
                "Cannot start picking batch in status: {$batch->status}"
            );
        }

        $batch->update([
            'status' => 'picking',
            'started_at' => now(),
        ]);

        Log::info('Batch picking started', [
            'batch_id' => $batch->id,
            'assigned_to' => $batch->assigned_to,
        ]);

        return $batch->fresh();
    }

    /**
     * Record picked quantity for a task
     */
    public function recordPick(
        PickingTask $task,
        float $quantity,
        ?string $notes = null
    ): PickingTask {
        return DB::transaction(function () use ($task, $quantity, $notes) {
            // Validate quantity
            if ($quantity > $task->quantity_to_pick) {
                throw new \Exception(
                    "Picked quantity ({$quantity}) exceeds required quantity ({$task->quantity_to_pick})"
                );
            }

            if ($quantity <= 0) {
                throw new \Exception('Picked quantity must be greater than zero');
            }

            $newTotal = $task->quantity_picked + $quantity;

            // Update task
            $updates = [
                'quantity_picked' => $newTotal,
                'status' => $newTotal >= $task->quantity_to_pick ? 'picked' : 'picking',
            ];

            if ($newTotal >= $task->quantity_to_pick) {
                $updates['picked_at'] = now();
            }

            if ($notes) {
                $updates['notes'] = $notes;
            }

            $task->update($updates);

            // Update fulfillment item
            $task->fulfillmentItem->increment('quantity_picked', $quantity);

            // Update batch progress
            $batch = $task->batch;
            $completedTasks = $batch->pickingTasks()->picked()->count();
            $batch->update(['picked_items' => $completedTasks]);

            // Check if batch is complete
            if ($batch->isComplete()) {
                $batch->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);

                Log::info('Batch picking completed', [
                    'batch_id' => $batch->id,
                    'total_items' => $batch->total_items,
                ]);

                $this->advanceFullyPickedFulfillments($batch);
            }

            Log::info('Pick recorded', [
                'task_id' => $task->id,
                'batch_id' => $batch->id,
                'quantity_picked' => $quantity,
                'task_complete' => $task->isComplete(),
            ]);

            return $task->fresh();
        });
    }

    /**
     * Advance fulfillments whose every item is fully picked to `picked`
     * via the single transition owner. Only fulfillments still in `picking`
     * move; anything else is left for its own flow (never forced).
     */
    private function advanceFullyPickedFulfillments(FulfillmentBatch $batch): void
    {
        $fulfillmentIds = $batch->pickingTasks()->distinct()->pluck('fulfillment_item_id');
        $itemFulfillmentIds = \App\Models\Fulfillment\FulfillmentItem::whereIn('id', $fulfillmentIds)
            ->distinct()
            ->pluck('fulfillment_id');

        foreach (Fulfillment::whereIn('id', $itemFulfillmentIds)->get() as $fulfillment) {
            if ($fulfillment->status !== 'picking') {
                continue;
            }
            $allPicked = $fulfillment->items()
                ->whereColumn('quantity_picked', '<', 'quantity')
                ->doesntExist();
            if ($allPicked) {
                $this->transitions->transition($fulfillment, 'picked', ['reason' => 'batch_completed']);
            }
        }
    }

    /**
     * Skip a picking task (out of stock, damaged, etc.)
     */
    public function skipTask(PickingTask $task, string $reason): PickingTask
    {
        $task->update([
            'status' => 'skipped',
            'notes' => $reason,
        ]);

        Log::warning('Picking task skipped', [
            'task_id' => $task->id,
            'batch_id' => $task->batch_id,
            'reason' => $reason,
        ]);

        return $task->fresh();
    }

    /**
     * Cancel batch
     */
    public function cancelBatch(FulfillmentBatch $batch, string $reason): FulfillmentBatch
    {
        if (in_array($batch->status, ['completed', 'cancelled'])) {
            throw new \Exception(
                "Cannot cancel batch in status: {$batch->status}"
            );
        }

        return DB::transaction(function () use ($batch, $reason) {
            $batch->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'notes' => $reason,
            ]);

            // Update all pending tasks
            $batch->pickingTasks()
                ->whereIn('status', ['pending', 'picking'])
                ->update(['status' => 'skipped', 'notes' => 'Batch cancelled: ' . $reason]);

            Log::warning('Batch cancelled', [
                'batch_id' => $batch->id,
                'reason' => $reason,
            ]);

            return $batch->fresh();
        });
    }

    /**
     * Generate unique batch number
     */
    private function generateBatchNumber(): string
    {
        do {
            $number = 'BATCH-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            $exists = FulfillmentBatch::where('batch_number', $number)->exists();
        } while ($exists);

        return $number;
    }

    /**
     * Get next task in sequence for picker
     */
    public function getNextTask(FulfillmentBatch $batch): ?PickingTask
    {
        return $batch->pickingTasks()
            ->pending()
            ->bySequence()
            ->first();
    }

    /**
     * Get pending fulfillments for batch creation
     */
    public function getPendingFulfillments(int $warehouseId, int $limit = 10): Collection
    {
        return Fulfillment::where('warehouse_id', $warehouseId)
            ->where('status', 'pending')
            ->with('items.productLocation.location')
            ->limit($limit)
            ->get();
    }
}

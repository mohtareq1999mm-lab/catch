<?php

namespace App\Services\Fulfillment;

use App\Models\Fulfillment\FulfillmentBatch;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\Fulfillment;
use App\Services\Warehouse\WarehouseService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BatchPickingService
{
    public function __construct(
        private FulfillmentTransition $transitions,
        private WarehouseService $warehouses,
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
            if ($fulfillments->isEmpty()) {
                throw new \InvalidArgumentException('Cannot create a batch from zero fulfillments');
            }

            // Single-operation rule (§22): same warehouse + same Order Flow +
            // same current stage. Anything else is a separate operation.
            $this->assertSingleBatchScope($fulfillments, $warehouseId);

            // Explicit warehouse wins, else ACTIVE default; inactive rejected.
            // Existing fulfillments/batches continue after a later deactivation.
            $warehouse = $this->warehouses->resolveForNewFulfillment($warehouseId);
            $warehouseId = $warehouse->id;

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

            // P4-2/P4-9: lock every fulfillment row and re-verify `pending`
            // under lock. The double-batch loser blocks on these locks, then
            // fails the recheck instead of duplicating tasks.
            $fulfillmentIds = $fulfillments->pluck('id')->all();
            $lockedFulfillments = Fulfillment::whereIn('id', $fulfillmentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($lockedFulfillments->count() !== count($fulfillmentIds)) {
                throw new \RuntimeException('Batch creation: fulfillment row missing under lock');
            }
            foreach ($lockedFulfillments as $locked) {
                if ($locked->status !== 'pending') {
                    throw new \RuntimeException(
                        "Batch scope mismatch: fulfillment #{$locked->id} status {$locked->status} != pending"
                    );
                }
            }

            // P5-5: locked scope revalidation. The initial
            // assertSingleBatchScope ran on pre-lock reads; re-run the same
            // Warehouse + Flow + Stage checks on freshly loaded rows so the
            // final scope decision uses locked truth. Orders are read fresh
            // and read-only — batch never writes order state.
            $lockedFulfillments->each(fn ($f) => $f->loadMissing('order'));
            $this->assertSingleBatchScope($lockedFulfillments, $warehouseId);

            // Collect all fulfillment items across all fulfillments. P4-2:
            // only items with REMAINING quantity gain a task, and the task
            // carries the remaining quantity — never the full item quantity.
            $allItems = collect();
            $remainingByItem = [];
            foreach ($lockedFulfillments as $locked) {
                $items = FulfillmentItem::where('fulfillment_id', $locked->id)
                    ->whereNotNull('product_location_id')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($items as $item) {
                    $remaining = (float) $item->quantity - (float) $item->quantity_picked;
                    if ($remaining <= 0) {
                        continue; // fully picked: no task
                    }
                    // Symmetric cross-flow guard (Phase 9, both directions):
                    // an item with ANY open task must not gain a second one.
                    $open = PickingTask::where('fulfillment_item_id', $item->id)
                        ->whereIn('status', ['pending', 'assigned', 'picking'])
                        ->lockForUpdate()
                        ->first();
                    if ($open) {
                        throw new \RuntimeException(
                            "Batch creation refused: fulfillment item #{$item->id} already has open picking task #{$open->id} (status: {$open->status})"
                        );
                    }
                    $remainingByItem[$item->id] = $remaining;
                    $allItems->push($item);
                }
            }

            // P5-4: a batch with zero pickable items is never valid (e.g.
            // manually-crafted pending + fully-picked state). Refuse before
            // any task exists or any fulfillment transitions — the enclosing
            // transaction rolls everything back, so no partial state survives.
            if ($allItems->isEmpty()) {
                throw new \InvalidArgumentException('Cannot create a batch with zero pickable items');
            }

            // Group by location for optimized picking route
            $itemsByLocation = $allItems->groupBy('product_location_id');
            // Phase 9: order map for fan-out denorm (avoids N+1 on item->fulfillment).
            $orderByFulfillment = $fulfillments->pluck('order_id', 'id');

            // Create picking tasks ordered by location priority
            $sequence = 1;
            $totalItems = 0;

            foreach ($itemsByLocation as $locationId => $items) {
                foreach ($items as $item) {
                    PickingTask::create([
                        'batch_id' => $batch->id,
                        'fulfillment_item_id' => $item->id,
                        'product_location_id' => $locationId,
                        // Phase 9: fan-out traceability denorm (never rely on
                        // joins alone when attributing picked units to orders).
                        'order_id' => $orderByFulfillment[$item->fulfillment_id] ?? null,
                        'order_item_id' => $item->order_item_id,
                        'quantity_to_pick' => $remainingByItem[$item->id] ?? (float) $item->quantity,
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
     * Enforce: one warehouse + one Order Flow + one current stage per batch
     * operation. Context-rich errors name the offending fulfillment/order.
     *
     * @throws \RuntimeException on scope mismatch.
     */
    private function assertSingleBatchScope(Collection $fulfillments, ?int $warehouseId): void
    {
        $loaded = $fulfillments->each(fn ($f) => $f->loadMissing('order'));
        $first = $loaded->first();
        $expectedWarehouse = $warehouseId ?? (int) $first->warehouse_id;
        $expectedFlow = $first->order?->flow_id;
        $expectedStage = $first->order?->status;

        foreach ($loaded as $fulfillment) {
            // Fail fast: batch creation moves pending→picking via the owner.
            // Anything else surfaces here, not mid-loop after tasks exist.
            if ($fulfillment->status !== 'pending') {
                throw new \RuntimeException(
                    "Batch scope mismatch: fulfillment #{$fulfillment->id} status {$fulfillment->status} != pending"
                );
            }
            if ((int) $fulfillment->warehouse_id !== (int) $expectedWarehouse) {
                throw new \RuntimeException(
                    "Batch scope mismatch: fulfillment #{$fulfillment->id} warehouse {$fulfillment->warehouse_id} != {$expectedWarehouse}"
                );
            }
            $flow = $fulfillment->order?->flow_id;
            $stage = $fulfillment->order?->status;
            if ($flow !== $expectedFlow) {
                throw new \RuntimeException(
                    "Batch scope mismatch: order #{$fulfillment->order_id} flow {$flow} != {$expectedFlow} (same Order Flow required)"
                );
            }
            if ($stage !== $expectedStage) {
                throw new \RuntimeException(
                    "Batch scope mismatch: order #{$fulfillment->order_id} stage {$stage} != {$expectedStage} (same current stage required)"
                );
            }
        }
    }

    /**
     * Assign batch to user. Phase 1 (T1): first-winner-wins under row lock.
     * NULL assignee → assign; same user → idempotent; another user → conflict.
     */
    public function assignBatch(FulfillmentBatch $batch, int $userId): FulfillmentBatch
    {
        return DB::transaction(function () use ($batch, $userId) {
            $locked = FulfillmentBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (!in_array($locked->status, ['pending', 'assigned'], true)) {
                throw new \RuntimeException(
                    "Cannot assign batch in status: {$locked->status}"
                );
            }

            if ($locked->assigned_to !== null && (int) $locked->assigned_to !== $userId) {
                throw new \RuntimeException(
                    "Batch #{$locked->id} is already assigned to user {$locked->assigned_to}"
                );
            }

            $locked->update([
                'assigned_to' => $userId,
                'status' => 'assigned',
            ]);

            Log::info('Batch assigned to user', [
                'batch_id' => $locked->id,
                'user_id' => $userId,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Start picking batch. Phase 1 (T1): locked assigned → picking transition.
     */
    public function startPicking(FulfillmentBatch $batch): FulfillmentBatch
    {
        return DB::transaction(function () use ($batch) {
            $locked = FulfillmentBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'assigned') {
                throw new \RuntimeException(
                    "Cannot start picking batch in status: {$locked->status}"
                );
            }

            $locked->update([
                'status' => 'picking',
                'started_at' => now(),
            ]);

            Log::info('Batch picking started', [
                'batch_id' => $locked->id,
                'assigned_to' => $locked->assigned_to,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Record picked quantity for a task. Direct quantity entry (no scan),
     * sharing confirm()'s state/claim authority (P4-6 guard parity).
     */
    public function recordPick(
        PickingTask $task,
        float $quantity,
        ?string $notes = null,
        ?int $userId = null,
        bool $override = false
    ): PickingTask {
        return DB::transaction(function () use ($task, $quantity, $notes, $userId, $override) {
            // Phase 8: lock + validate against REMAINING (not just required).
            $locked = PickingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();

            // P4-6: never mutate terminal tasks; claimed tasks require the
            // claimant (or an explicit override) — same authority as confirm.
            if (!in_array($locked->status, ['assigned', 'picking'], true)) {
                throw new \Exception(
                    "Cannot record pick on task #{$locked->id} in status {$locked->status}"
                );
            }
            if (!$override && $locked->claimed_by !== null
                && ($userId === null || (int) $locked->claimed_by !== $userId)) {
                throw new \Exception(
                    "Picking task #{$locked->id} is claimed by another worker"
                );
            }

            $remaining = (float) $locked->quantity_to_pick - (float) $locked->quantity_picked;

            if ($quantity > $remaining) {
                throw new \Exception(
                    "Picked quantity ({$quantity}) exceeds remaining quantity ({$remaining})"
                );
            }

            if ($quantity <= 0) {
                throw new \Exception('Picked quantity must be greater than zero');
            }

            $task = $locked;
            $newTotal = (float) $task->quantity_picked + $quantity;

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

            // Update batch progress for batch tasks (order-flow tasks carry
            // batch_id NULL and have no batch to advance). P5-2: single
            // completion authority — recount, mark, and advance in one place.
            $batch = $task->batch;
            if ($batch !== null) {
                $batch = $this->completeBatchIfReady($batch);
            }

            Log::info('Pick recorded', [
                'task_id' => $task->id,
                'batch_id' => $task->batch_id,
                'quantity_picked' => $quantity,
                'task_complete' => $task->isComplete(),
            ]);

            return $task->fresh();
        });
    }

    /**
     * Phase 9: recompute batch progress after engine-confirmed picks.
     * Batch scan flow = PickingExecutionService::confirm + this refresh
     * (recordPick remains for direct quantity entry with the same guards).
     * P5-2: delegates to the single completion authority.
     */
    public function refreshBatchProgress(FulfillmentBatch $batch): FulfillmentBatch
    {
        return $this->completeBatchIfReady($batch);
    }

    /**
     * P5-2: the single batch completion authority. Locks the batch,
     * recounts picked tasks, and — only when the existing task-based
     * condition holds and the batch is not already completed — marks it
     * completed with a stable timestamp and advances fully picked
     * fulfillments. Idempotent: completed or not-ready batches are no-ops.
     */
    private function completeBatchIfReady(FulfillmentBatch $batch): FulfillmentBatch
    {
        return DB::transaction(function () use ($batch) {
            $locked = FulfillmentBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $completedTasks = $locked->pickingTasks()->where('status', 'picked')->count();
            if ($completedTasks !== (int) $locked->picked_items) {
                $locked->update(['picked_items' => $completedTasks]);
            }

            if ($locked->isComplete() && $locked->status !== 'completed') {
                $locked->update(['status' => 'completed', 'completed_at' => now()]);

                Log::info('Batch picking completed', [
                    'batch_id' => $locked->id,
                    'total_items' => $locked->total_items,
                ]);

                $this->advanceFullyPickedFulfillments($locked);
            }

            return $locked->fresh();
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
     * Skip a picking task (out of stock, damaged, etc.). P4-3: locked,
     * state-guarded, reason-required. Skipped tasks stay out of the open
     * set, so a retry task can be created later (no history system).
     *
     * @throws \InvalidArgumentException on empty reason.
     * @throws \RuntimeException on non-open (terminal) task.
     */
    public function skipTask(PickingTask $task, string $reason): PickingTask
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Skip reason is required');
        }

        return DB::transaction(function () use ($task, $reason) {
            $locked = PickingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();

            if (!in_array($locked->status, ['pending', 'assigned', 'picking'], true)) {
                throw new \RuntimeException(
                    "Cannot skip picking task #{$locked->id} in status {$locked->status}"
                );
            }

            $locked->update([
                'status' => 'skipped',
                'notes' => $reason,
            ]);

            Log::warning('Picking task skipped', [
                'task_id' => $locked->id,
                'batch_id' => $locked->batch_id,
                'reason' => $reason,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * P5-1: retry skipped batch tasks by creating exactly one replacement
     * task per skipped item that still has remaining quantity. The skipped
     * rows stay terminal (reason/history preserved); replacements carry the
     * same batch membership and the item's current linkage/remaining quantity.
     *
     * Denominator note: replacement is a 1:1 swap (one dead row superseded
     * by one live row), so total_items — the live-task count the existing
     * picked_items == total_items completion condition relies on — stays
     * correct WITHOUT adjustment. No increment is needed and none is made.
     *
     * Guards (all under lock): batch non-terminal; per item, remaining > 0
     * and no open task (loud refusal preserves exactly-one-open-task). Items
     * without a placement are skipped like the creation doors skip them —
     * the operator assigns placement first, then retries again.
     *
     * @return PickingTask[] created replacement tasks (empty when nothing retryable)
     *
     * @throws \InvalidArgumentException on empty reason.
     * @throws \RuntimeException on terminal batch or open-task conflict.
     */
    public function retrySkippedTasks(FulfillmentBatch $batch, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Retry reason is required');
        }

        return DB::transaction(function () use ($batch, $reason) {
            $locked = FulfillmentBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['completed', 'cancelled'], true)) {
                throw new \RuntimeException(
                    "Cannot retry skipped tasks on batch #{$locked->id} in status {$locked->status}"
                );
            }

            $created = [];
            $sequence = (int) ($locked->pickingTasks()->max('sequence') ?? 0);
            // One replacement per ITEM (several dead rows may share an item
            // across retry cycles); the open-task check below then only ever
            // sees pre-existing work, never this call's own replacements.
            $skippedByItem = $locked->pickingTasks()
                ->where('status', 'skipped')
                ->orderBy('id')
                ->get()
                ->groupBy('fulfillment_item_id');

            foreach ($skippedByItem as $itemId => $rows) {
                $task = $rows->first();
                $item = FulfillmentItem::whereKey($itemId)->lockForUpdate()->firstOrFail();
                $remaining = (float) $item->quantity - (float) $item->quantity_picked;
                if ($remaining <= 0) {
                    continue; // fully picked meanwhile: nothing to retry
                }
                if ($item->product_location_id === null) {
                    continue; // operator assigns placement first (creation-door parity)
                }

                // Exactly-one-open-task per item (both flows): loud refusal.
                $open = PickingTask::where('fulfillment_item_id', $item->id)
                    ->whereIn('status', ['pending', 'assigned', 'picking'])
                    ->lockForUpdate()
                    ->first();
                if ($open) {
                    throw new \RuntimeException(
                        "Retry refused: fulfillment item #{$item->id} already has open picking task #{$open->id} (status: {$open->status})"
                    );
                }

                $created[] = PickingTask::create([
                    'batch_id' => $locked->id,
                    'fulfillment_item_id' => $item->id,
                    'product_location_id' => $item->product_location_id,
                    'order_id' => $task->order_id,
                    'order_item_id' => $item->order_item_id,
                    'quantity_to_pick' => $remaining,
                    'quantity_picked' => 0,
                    'status' => 'pending',
                    'sequence' => ++$sequence,
                    'notes' => 'Retry: ' . $reason,
                ]);
            }

            Log::info('Batch skipped tasks retried', [
                'batch_id' => $locked->id,
                'reason' => $reason,
                'replacements' => count($created),
            ]);

            return $created;
        });
    }

    /**
     * Cancel batch.
     * Phase 7 (P7-5): reason is required (trim-validated, same convention
     * as FulfillmentService::cancelFulfillment and PackingService::cancelTask).
     */
    public function cancelBatch(FulfillmentBatch $batch, string $reason): FulfillmentBatch
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Cancellation reason is required');
        }

        if (in_array($batch->status, ['completed', 'cancelled'])) {
            throw new \Exception(
                "Cannot cancel batch in status: {$batch->status}"
            );
        }

        return DB::transaction(function () use ($batch, $reason) {
            $locked = FulfillmentBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['completed', 'cancelled'], true)) {
                throw new \Exception(
                    "Cannot cancel batch in status: {$locked->status}"
                );
            }

            $locked->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'notes' => $reason,
            ]);

            // P4-4: all operational open tasks — pending, assigned AND
            // picking — are released/skipped (cancelFulfillment parity).
            // Picked work is complete and stays untouched.
            $locked->pickingTasks()
                ->whereIn('status', ['pending', 'assigned', 'picking'])
                ->update([
                    'status' => 'skipped',
                    'notes' => 'Batch cancelled: ' . $reason,
                    'claimed_by' => null,
                    'claimed_at' => null,
                    'claim_expires_at' => null,
                ]);

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

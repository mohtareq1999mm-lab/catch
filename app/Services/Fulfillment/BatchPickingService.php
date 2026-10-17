<?php

namespace App\Services\Fulfillment;

use App\Models\Fulfillment\FulfillmentBatch;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\ProductLocation;
use App\Services\Warehouse\WarehouseService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BatchPickingService
{
    public function __construct(
        private FulfillmentTransition $transitions,
        private WarehouseService $warehouses,
    ) {}
    /**
     * Create a batch from multiple fulfillments (wave picking).
     *
     * Idempotent when $idempotencyKey is provided: a replay returns the
     * original batch (with tasks) instead of creating a duplicate. Keyless
     * calls keep the legacy behavior (one call = one batch).
     */
    public function createBatchFromFulfillments(
        Collection $fulfillments,
        ?int $warehouseId = null,
        string $type = 'wave',
        ?string $idempotencyKey = null
    ): FulfillmentBatch {
        return DB::transaction(function () use ($fulfillments, $warehouseId, $type, $idempotencyKey) {
            if ($fulfillments->isEmpty()) {
                throw new \InvalidArgumentException(__('wms.batch.zero_fulfillments'));
            }

            // Normalize: blank keys are keyless (rule also rejects them at HTTP).
            if ($idempotencyKey !== null && trim($idempotencyKey) === '') {
                $idempotencyKey = null;
            }

            // Explicit warehouse wins, else ACTIVE default; inactive rejected.
            // Resolved BEFORE the replay check so the replay is
            // warehouse-scoped (a foreign-warehouse key refuses instead of
            // leaking), and BEFORE scope validation so replays skip the
            // pending-only guard (their fulfillments already moved on).
            $warehouse = $this->warehouses->resolveForNewFulfillment($warehouseId);
            $warehouseId = $warehouse->id;

            // Idempotent replay (warehouse-scoped): the same key in the same
            // warehouse returns the original batch. A key bound to another
            // warehouse refuses instead of leaking foreign batch detail.
            if ($idempotencyKey !== null) {
                $replay = FulfillmentBatch::where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($replay !== null) {
                    if ((int) $replay->warehouse_id !== (int) $warehouseId) {
                        throw new \RuntimeException(
                            __('wms.batch.key_warehouse', ['warehouse' => $replay->warehouse_id, 'expected' => $warehouseId])
                        );
                    }

                    return $replay->load('pickingTasks.productLocation.location');
                }
            }

            // Single-operation rule (§22): same warehouse + same Order Flow +
            // same current stage. Anything else is a separate operation.
            $this->assertSingleBatchScope($fulfillments, $warehouseId);

            // Create batch row with bounded retry on batch_number collision.
            // Returns null when the idempotency race was lost (same-key row
            // won) so the caller replays the scoped row instead of building
            // a second batch. Never returns a half-built batch: either the
            // row insert succeeded or nothing was written.
            $batch = $this->insertBatchRow($warehouseId, $type, $idempotencyKey);
            if ($batch === null) {
                $replay = FulfillmentBatch::where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ((int) $replay->warehouse_id !== (int) $warehouseId) {
                    throw new \RuntimeException(
                        __('wms.batch.key_warehouse', ['warehouse' => $replay->warehouse_id, 'expected' => $warehouseId])
                    );
                }

                return $replay->load('pickingTasks.productLocation.location');
            }
            $batchNumber = $batch->batch_number;

            // P4-2/P4-9: lock every fulfillment row and re-verify `pending`
            // under lock. The double-batch loser blocks on these locks, then
            // fails the recheck instead of duplicating tasks.
            $fulfillmentIds = $fulfillments->pluck('id')->all();
            $lockedFulfillments = Fulfillment::whereIn('id', $fulfillmentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($lockedFulfillments->count() !== count($fulfillmentIds)) {
                throw new \RuntimeException(__('wms.batch.row_missing'));
            }
            foreach ($lockedFulfillments as $locked) {
                if ($locked->status !== 'pending') {
                    throw new \RuntimeException(
                        __('wms.batch.scope_status', ['id' => $locked->id, 'status' => $locked->status])
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
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($items as $item) {
                    if ($item->product_location_id === null) {
                        $placements = ProductLocation::query()
                            ->forSku((int) $item->product_id, $item->product_variant_id !== null ? (int) $item->product_variant_id : null)
                            ->where('product_locations.warehouse_id', (int) $locked->warehouse_id)
                            ->whereHas('location', fn ($q) => $q->placeable())
                            ->join('locations', 'product_locations.location_id', '=', 'locations.id')
                            ->orderBy('locations.priority', 'desc')
                            ->orderBy('product_locations.quantity', 'desc')
                            ->select('product_locations.*')
                            ->get();

                        if ($placements->isNotEmpty()) {
                            $rem = (float) $item->quantity - (float) $item->quantity_picked;
                            $sufficient = $placements->first(fn ($p) => $p->availableQuantity() >= $rem);
                            $chosen = $sufficient ?? $placements->first();
                            $item->update(['product_location_id' => $chosen->id]);
                        } else {
                            continue;
                        }
                    }

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
                            __('wms.batch.item_open_task', ['item' => $item->id, 'task' => $open->id, 'status' => $open->status])
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
                throw new \InvalidArgumentException(__('wms.batch.zero_pickable'));
            }

            // Group by location for optimized picking route
            $itemsByLocation = $allItems->groupBy('product_location_id');
            // Phase 9: order map for fan-out denorm (avoids N+1 on item->fulfillment).
            // Built from LOCKED rows so transitions and denorm share one truth.
            $orderByFulfillment = $lockedFulfillments->pluck('order_id', 'id');

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
            // Locked rows only: the pre-lock collection may be stale.
            foreach ($lockedFulfillments as $lockedFulfillment) {
                $this->transitions->transition($lockedFulfillment, 'picking', ['reason' => 'batch_created']);
            }

            // Converge each order (batch spans one flow/stage by scope, but
            // may span orders): after-commit, idempotent.
            $sync = app(\App\Services\Fulfillment\FulfillmentOrderSyncService::class);
            foreach ($lockedFulfillments->pluck('order_id')->unique()->all() as $orderId) {
                $sync->announce((int) $orderId, 'picking_started');
            }

            Log::info('Batch picking created', [
                'batch_id' => $batch->id,
                'batch_number' => $batchNumber,
                'warehouse_id' => $warehouseId,
                'fulfillments_count' => $lockedFulfillments->count(),
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
                    __('wms.batch.scope_status', ['id' => $fulfillment->id, 'status' => $fulfillment->status])
                );
            }
            if ((int) $fulfillment->warehouse_id !== (int) $expectedWarehouse) {
                throw new \RuntimeException(
                    __('wms.batch.scope_warehouse', ['id' => $fulfillment->id, 'warehouse' => $fulfillment->warehouse_id, 'expected' => $expectedWarehouse])
                );
            }
            $flow = $fulfillment->order?->flow_id;
            $stage = $fulfillment->order?->status;
            if ($flow !== $expectedFlow) {
                throw new \RuntimeException(
                    __('wms.batch.scope_flow', ['order' => $fulfillment->order_id, 'flow' => $flow, 'expected' => $expectedFlow])
                );
            }
            if ($stage !== $expectedStage) {
                throw new \RuntimeException(
                    __('wms.batch.scope_stage', ['order' => $fulfillment->order_id, 'stage' => $stage, 'expected' => $expectedStage])
                );
            }
        }
    }

    /**
     * Assignee eligibility: a worker homed in another warehouse cannot take
     * this batch (cross-warehouse assignment rejected). Global holders of
     * manage-warehouse and unscoped users (null home: admins created before
     * warehouse scoping) remain eligible. Fail-closed on missing users.
     *
     * @throws \RuntimeException on cross-warehouse assignment.
     */
    private function assertAssigneeEligible(int $warehouseId, int $userId): void
    {
        // Same users table via either model; Marvel carries HasRoles +
        // warehouse_id fillable, App model is the batch relation target.
        $assignee = class_exists(\Marvel\Database\Models\User::class)
            ? \Marvel\Database\Models\User::whereKey($userId)->first()
            : \App\Models\User::whereKey($userId)->first();
        if ($assignee === null) {
            throw new \RuntimeException(
                __('wms.batch.assignee_warehouse', ['user' => $userId, 'warehouse' => $warehouseId])
            );
        }

        try {
            if (method_exists($assignee, 'hasPermissionTo') && $assignee->hasPermissionTo('manage-warehouse', 'api')) {
                return;
            }
        } catch (\Throwable) {
            // Fall through to the home-warehouse check (fail-closed).
        }

        $home = $assignee->warehouse_id ?? null;
        if ($home !== null && (int) $home !== (int) $warehouseId) {
            throw new \RuntimeException(
                __('wms.batch.assignee_warehouse', ['user' => $userId, 'warehouse' => $warehouseId])
            );
        }
    }

    /**
     * Assign batch to user. Phase 1 (T1): first-winner-wins under row lock.
     * NULL assignee → assign; same user → idempotent; another user → conflict
     * unless $reassign is true (privileged reassignment: the controller gates
     * it behind batch.manage; the service only records the intent).
     */
    public function assignBatch(FulfillmentBatch $batch, int $userId, bool $reassign = false): FulfillmentBatch
    {
        return DB::transaction(function () use ($batch, $userId, $reassign) {
            $locked = FulfillmentBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (!in_array($locked->status, ['pending', 'assigned'], true)) {
                throw new \RuntimeException(
                    __('wms.batch.assign_conflict_status', ['status' => $locked->status])
                );
            }

            if ($locked->assigned_to !== null && (int) $locked->assigned_to !== $userId && !$reassign) {
                // First-winner contention → HTTP 409 at the adapter.
                throw new \App\Exceptions\WmsConflictException(
                    __('wms.batch.already_assigned', ['id' => $locked->id, 'user' => $locked->assigned_to])
                );
            }

            // Cross-warehouse assignment guard: the assignee must belong to
            // the batch warehouse, unless they hold global manage-warehouse
            // (which crosses warehouses by WarehouseAccess convention).
            $this->assertAssigneeEligible($locked->warehouse_id, $userId);

            $wasReassign = $locked->assigned_to !== null && (int) $locked->assigned_to !== $userId;

            $locked->update([
                'assigned_to' => $userId,
                'status' => 'assigned',
            ]);

            Log::info($wasReassign ? 'Batch reassigned to user' : 'Batch assigned to user', [
                'batch_id' => $locked->id,
                'user_id' => $userId,
                'reassign' => $wasReassign,
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
                    __('wms.batch.start_status', ['status' => $locked->status])
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
     *
     * Idempotent replay: an op_seq already applied returns the current
     * state without double-counting (confirm() parity for device retries).
     */
    public function recordPick(
        PickingTask $task,
        float $quantity,
        ?string $notes = null,
        ?int $userId = null,
        bool $override = false,
        ?int $opSeq = null
    ): PickingTask {
        return DB::transaction(function () use ($task, $quantity, $notes, $userId, $override, $opSeq) {
            // Phase 8: lock + validate against REMAINING (not just required).
            $locked = PickingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();

            $opSeq = (int) ($opSeq ?? 0);

            // Idempotent replay: op_seq already applied → return current state.
            if ($opSeq > 0 && $opSeq <= (int) $locked->op_seq) {
                Log::info('Record pick replay ignored', [
                    'task_id' => $locked->id, 'op_seq' => $opSeq, 'user_id' => $userId,
                ]);

                return $locked->fresh();
            }

            // P4-6: never mutate terminal tasks; claimed tasks require the
            // claimant (or an explicit override) — same authority as confirm.
            if (!in_array($locked->status, ['assigned', 'picking'], true)) {
                throw new \Exception(
                    __('wms.batch.record_pick_status', ['id' => $locked->id, 'status' => $locked->status])
                );
            }
            if (!$override && $locked->claimed_by !== null
                && ($userId === null || (int) $locked->claimed_by !== $userId)) {
                throw new \Exception(
                    __('wms.batch.claimed_other', ['id' => $locked->id])
                );
            }

            $remaining = (float) $locked->quantity_to_pick - (float) $locked->quantity_picked;

            if ($quantity > $remaining) {
                throw new \Exception(
                    __('wms.batch.pick_exceeds', ['quantity' => $quantity, 'remaining' => $remaining])
                );
            }

            if ($quantity <= 0) {
                throw new \Exception(__('wms.batch.pick_positive'));
            }

            $task = $locked;
            $newTotal = (float) $task->quantity_picked + $quantity;

            // Update task (op_seq advances like confirm(): replay watermark).
            $updates = [
                'quantity_picked' => $newTotal,
                'status' => $newTotal >= $task->quantity_to_pick ? 'picked' : 'picking',
                'op_seq' => max($opSeq, (int) $locked->op_seq),
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
            $freshItem = $task->fulfillmentItem->fresh();
            $itemComplete = (float) $freshItem->quantity_picked >= (float) $freshItem->quantity;
            $freshItem->update([
                'status' => $itemComplete ? 'picked' : 'picking',
                'picked_at' => $itemComplete ? now() : $freshItem->picked_at,
            ]);

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

            // Terminal batches never resurrect: cancelled stays cancelled,
            // completed stays completed (idempotent no-op).
            if (in_array($locked->status, ['cancelled', 'completed'], true)) {
                return $locked->fresh();
            }

            $completedTasks = $locked->pickingTasks()->where('status', 'picked')->count();
            if ($completedTasks !== (int) $locked->picked_items) {
                $locked->update(['picked_items' => $completedTasks]);
            }

            // Auto-completion from any non-terminal state (pending batches
            // whose tasks were all picked directly still close out); only
            // cancelled/completed are terminal and never transition here.
            if ($locked->isComplete() && !in_array($locked->status, ['cancelled', 'completed'], true)) {
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
     * Strict batch completion: rejects while required tasks remain
     * incomplete (422 at the adapter). Completed batches are idempotent
     * no-ops; cancelled batches refuse. Progress is always recounted from
     * task state — never trusted from the client.
     *
     * @throws \RuntimeException on wrong state or incomplete work.
     */
    public function completeBatch(FulfillmentBatch $batch): FulfillmentBatch
    {
        return DB::transaction(function () use ($batch) {
            $locked = FulfillmentBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'completed') {
                return $locked;
            }

            if (in_array($locked->status, ['cancelled'], true)) {
                throw new \RuntimeException(
                    __('wms.batch.complete_status', ['status' => $locked->status])
                );
            }

            if (!in_array($locked->status, ['assigned', 'picking'], true)) {
                throw new \RuntimeException(
                    __('wms.batch.complete_status', ['status' => $locked->status])
                );
            }

            $completedTasks = $locked->pickingTasks()->where('status', 'picked')->count();
            if ($completedTasks !== (int) $locked->picked_items) {
                $locked->update(['picked_items' => $completedTasks]);
                $locked->refresh();
            }

            if (!$locked->isComplete()) {
                throw new \RuntimeException(
                    __('wms.batch.incomplete', [
                        'id' => $locked->id,
                        'picked' => $locked->picked_items,
                        'total' => $locked->total_items,
                    ])
                );
            }

            $locked->update(['status' => 'completed', 'completed_at' => now()]);

            Log::info('Batch picking completed', [
                'batch_id' => $locked->id,
                'total_items' => $locked->total_items,
            ]);

            $this->advanceFullyPickedFulfillments($locked);

            return $locked->fresh();
        });
    }

    /**
     * Advance fulfillments whose every item is fully picked to `picked`
     * via the single transition owner. Only fulfillments still in `picking`
     * move; anything else is left for its own flow (never forced).
     *
     * Single aggregate read: one grouped query finds every fulfillment with
     * remaining (unpicked) items instead of one EXISTS query per
     * fulfillment. Per-fulfillment transitions stay individual and locked —
     * lifecycle writes are never bulk-updated past FulfillmentTransition.
     */
    private function advanceFullyPickedFulfillments(FulfillmentBatch $batch): void
    {
        $itemFulfillmentIds = $batch->pickingTasks()
            ->join('fulfillment_items', 'fulfillment_items.id', '=', 'picking_tasks.fulfillment_item_id')
            ->distinct()
            ->pluck('fulfillment_items.fulfillment_id');

        if ($itemFulfillmentIds->isEmpty()) {
            return;
        }

        // Incomplete-item counts per fulfillment across ALL of each
        // fulfillment's items (same semantics as the previous per-row
        // whereColumn check). Fulfillments absent from this map are fully
        // picked — including the degenerate zero-item case, as before.
        $incompleteByFulfillment = FulfillmentItem::whereIn('fulfillment_id', $itemFulfillmentIds->all())
            ->whereColumn('quantity_picked', '<', 'quantity')
            ->select('fulfillment_id', DB::raw('COUNT(*) AS incomplete_items'))
            ->groupBy('fulfillment_id')
            ->pluck('incomplete_items', 'fulfillment_id');

        $candidates = Fulfillment::whereIn('id', $itemFulfillmentIds->all())
            ->where('status', 'picking')
            ->get();

        $sync = app(\App\Services\Fulfillment\FulfillmentOrderSyncService::class);
        foreach ($candidates as $fulfillment) {
            if (!isset($incompleteByFulfillment[$fulfillment->id])) {
                $this->transitions->transition($fulfillment, 'picked', ['reason' => 'batch_completed']);
                $sync->announce((int) $fulfillment->order_id, 'picked', (int) $fulfillment->id);
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
            throw new \InvalidArgumentException(__('wms.batch.skip_reason'));
        }

        return DB::transaction(function () use ($task, $reason) {
            $locked = PickingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();

            if (!in_array($locked->status, ['pending', 'assigned', 'picking'], true)) {
                throw new \RuntimeException(
                    __('wms.batch.skip_status', ['id' => $locked->id, 'status' => $locked->status])
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
            throw new \InvalidArgumentException(__('wms.batch.retry_reason'));
        }

        return DB::transaction(function () use ($batch, $reason) {
            $locked = FulfillmentBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['completed', 'cancelled'], true)) {
                throw new \RuntimeException(
                    __('wms.batch.retry_status', ['id' => $locked->id, 'status' => $locked->status])
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
                    // Exactly-one-open-task conflict → HTTP 409 at the
                    // adapter via WmsConflictException (type, never text).
                    throw new \App\Exceptions\WmsConflictException(
                        __('wms.batch.retry_open_task', ['item' => $item->id, 'task' => $open->id, 'status' => $open->status])
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
            throw new \InvalidArgumentException(__('wms.batch.cancel_reason'));
        }

        if (in_array($batch->status, ['completed', 'cancelled'])) {
            throw new \Exception(
                __('wms.batch.cancel_status', ['status' => $batch->status])
            );
        }

        return DB::transaction(function () use ($batch, $reason) {
            $locked = FulfillmentBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['completed', 'cancelled'], true)) {
                throw new \Exception(
                    __('wms.batch.cancel_status', ['status' => $locked->status])
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
     * Mint a candidate batch number. Protected (not private) so tests can
     * force deterministic collisions and prove the insert retry path.
     */
    protected function generateBatchNumber(): string
    {
        do {
            // Cryptographic randomness (not time-based uniqid): concurrent
            // creators no longer mint identical candidates routinely. The
            // UNIQUE(batch_number) constraint stays the final authority and
            // insertBatchRow() retries the rare residual collision.
            $number = 'BATCH-' . date('Ymd') . '-' . strtoupper(Str::random(6));
            $exists = FulfillmentBatch::where('batch_number', $number)->exists();
        } while ($exists);

        return $number;
    }

    /**
     * Insert the batch row with bounded retry on batch_number collision.
     *
     * The UNIQUE(batch_number) constraint is the final authority: on a
     * duplicate-key failure the candidate is discarded and a fresh one is
     * minted (max 5 attempts, then the error is rethrown — never converted
     * into a false success). A failed INSERT rolls back statement-only on
     * InnoDB, so retrying inside the enclosing transaction is safe and the
     * batch creation stays atomic with its task creation below.
     *
     * Returns null ONLY when a concurrent same-key writer won the
     * UNIQUE(idempotency_key) race: the caller must replay that row.
     */
    private function insertBatchRow(?int $warehouseId, string $type, ?string $idempotencyKey): ?FulfillmentBatch
    {
        $attempts = 0;

        while (true) {
            $attempts++;
            $candidate = $this->generateBatchNumber();

            try {
                return FulfillmentBatch::create([
                    'warehouse_id' => $warehouseId,
                    'batch_number' => $candidate,
                    'idempotency_key' => $idempotencyKey,
                    'status' => 'pending',
                    'type' => $type,
                    'total_items' => 0,
                    'picked_items' => 0,
                ]);
            } catch (QueryException $e) {
                // Narrow: only duplicate-key failures are classified.
                // Anything else (deadlock, connection, FK/NOT-NULL/CHECK
                // constraint) rethrows. Note: bare SQLSTATE 23000 is
                // deliberately NOT matched — on MySQL it also covers FK
                // (1451/1452) and NOT-NULL (1048) violations, which must
                // never be mistaken for a uniqueness conflict.
                if (!$this->isDuplicateKey($e)) {
                    throw $e;
                }

                // Idempotency race lost: probe with a LOCKING read. Under
                // REPEATABLE READ a snapshot read could predate the winner's
                // commit and miss the row; lockForUpdate blocks until the
                // winner commits, then sees it.
                $keyRow = $idempotencyKey !== null
                    ? FulfillmentBatch::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first()
                    : null;
                if ($keyRow !== null) {
                    return null;
                }

                // Pure batch_number collision: regenerate within the bound.
                if ($attempts >= 5) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Duplicate-key detector for uniqueness races. Matches ONLY
     * engine-specific unique-violation codes: MySQL 1062, SQLite 19,
     * PostgreSQL 23505. Bare SQLSTATE 23000 is excluded on purpose —
     * it also covers FK / NOT-NULL / CHECK violations.
     *
     * Supported stack is MySQL-only (config/database.php, phpunit forces
     * mysql); the SQLite/Postgres branches exist so a misconfigured
     * connection fails with the same classification instead of a novel
     * one. In-transaction INSERT retry relies on InnoDB statement-level
     * rollback and does NOT hold on other engines.
     */
    private function isDuplicateKey(QueryException $e): bool
    {
        $info = $e->errorInfo ?? null;
        if (!is_array($info)) {
            return false;
        }

        return ($info[1] ?? null) === 1062
            || ($info[1] ?? null) === 19
            || ($info[0] ?? null) === '23505';
    }

    /**
     * Get next task in sequence for picker
     */
    public function getNextTask(FulfillmentBatch $batch): ?PickingTask
    {
        $task = $batch->pickingTasks()
            ->pending()
            ->bySequence()
            ->first();

        if ($task && $task->product_location_id !== null) {
            $taskItem = $task->fulfillmentItem;
            $productId = $taskItem?->product_id;
            $variantId = $taskItem?->product_variant_id;

            $siblingTasks = $batch->pickingTasks()
                ->where('product_location_id', $task->product_location_id)
                ->whereIn('status', ['pending', 'assigned', 'picking'])
                ->whereHas('fulfillmentItem', function ($q) use ($productId, $variantId) {
                    $q->where('product_id', $productId);
                    if ($variantId !== null) {
                        $q->where('product_variant_id', $variantId);
                    } else {
                        $q->whereNull('product_variant_id');
                    }
                })
                ->get(['id', 'order_id', 'quantity_to_pick', 'quantity_picked']);

            $task->consolidated_info = [
                'is_consolidated' => $siblingTasks->count() > 1,
                'total_quantity' => (float) $siblingTasks->sum(fn ($t) => (float) $t->quantity_to_pick - (float) $t->quantity_picked),
                'orders_count' => $siblingTasks->pluck('order_id')->filter()->unique()->count() ?: 1,
                'task_ids' => $siblingTasks->pluck('id')->values()->all(),
            ];
        }

        return $task;
    }

    /**
     * Confirm consolidated location pick for a batch.
     *
     * Validates scanned location barcode and product/variant barcode/SKU,
     * then distributes the picked quantity across all pending/assigned tasks
     * for that location in sequence within a single atomic database transaction.
     *
     * @param FulfillmentBatch $batch
     * @param array{location: string, product: string, quantity: float|int, op_seq?: int} $scan
     * @param int $userId
     * @param bool $override
     * @return array
     */
    public function confirmLocationPick(
        FulfillmentBatch $batch,
        array $scan,
        int $userId,
        bool $override = false
    ): array {
        return DB::transaction(function () use ($batch, $scan, $userId, $override) {
            $quantity = (float) ($scan['quantity'] ?? 0);
            if ($quantity <= 0) {
                throw new \InvalidArgumentException(__('wms.batch.pick_positive'));
            }

            /** @var \App\Services\Warehouse\BarcodeResolver $barcodes */
            $barcodes = app(\App\Services\Warehouse\BarcodeResolver::class);

            // 1. Resolve Location
            $locationData = $barcodes->resolve((string) ($scan['location'] ?? ''));
            if ($locationData['kind'] !== 'location') {
                throw new \InvalidArgumentException(__('wms.picking.location_not_found', ['scanned' => $scan['location'] ?? '']));
            }
            $locationId = (int) $locationData['id'];

            // 2. Resolve Product or Variant
            try {
                $productData = $barcodes->resolveProduct((string) ($scan['product'] ?? ''));
            } catch (\App\Exceptions\UnknownBarcodeException) {
                $productData = $barcodes->resolve((string) ($scan['product'] ?? ''));
            }

            if (($productData['kind'] ?? '') === 'variant') {
                $variantId = (int) $productData['id'];
                $productId = (int) ($productData['product_id'] ?? 0);
            } else {
                $productId = (int) ($productData['id'] ?? 0);
                $variantId = null;
            }

            // 3. Find matching product_locations for this physical location & warehouse
            $plQuery = ProductLocation::where('location_id', $locationId)
                ->where('warehouse_id', $batch->warehouse_id)
                ->where('product_id', $productId);

            if ($variantId !== null) {
                $plQuery->where('product_variant_id', $variantId);
            } else {
                $plQuery->whereNull('product_variant_id');
            }

            $plIds = $plQuery->pluck('id')->all();

            // Fallback to any placements at that location if not found with exact variant filter
            if (empty($plIds)) {
                $plIds = ProductLocation::where('location_id', $locationId)
                    ->where('warehouse_id', $batch->warehouse_id)
                    ->pluck('id')
                    ->all();
            }

            if (empty($plIds)) {
                throw new \RuntimeException(__('wms.batch.location_no_tasks'));
            }

            // 4. Lock open tasks in this batch at this location
            $tasks = PickingTask::where('batch_id', $batch->id)
                ->whereIn('product_location_id', $plIds)
                ->whereIn('status', ['pending', 'assigned', 'picking'])
                ->with(['fulfillmentItem'])
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            // Filter strictly to matching product or variant
            $matchingTasks = $tasks->filter(function ($t) use ($productId, $variantId) {
                $item = $t->fulfillmentItem;
                if (!$item) {
                    return false;
                }
                if ($variantId !== null) {
                    return (int) $item->product_variant_id === $variantId;
                }
                return (int) $item->product_id === $productId && $item->product_variant_id === null;
            });

            if ($matchingTasks->isEmpty()) {
                throw new \RuntimeException(__('wms.batch.location_no_tasks'));
            }

            $totalRemaining = (float) $matchingTasks->sum(fn ($t) => (float) $t->quantity_to_pick - (float) $t->quantity_picked);
            if ($quantity > $totalRemaining) {
                throw new \RuntimeException(
                    __('wms.batch.pick_exceeds', ['quantity' => $quantity, 'remaining' => $totalRemaining])
                );
            }

            // 5. Distribute quantity sequentially across matching tasks
            $qtyToAllocate = $quantity;
            $updatedTasks = [];
            $now = now();
            $opSeq = (int) ($scan['op_seq'] ?? 0);

            foreach ($matchingTasks as $task) {
                if ($qtyToAllocate <= 0) {
                    break;
                }

                $taskRemaining = (float) $task->quantity_to_pick - (float) $task->quantity_picked;
                if ($taskRemaining <= 0) {
                    continue;
                }

                $alloc = min($qtyToAllocate, $taskRemaining);
                $newPicked = (float) $task->quantity_picked + $alloc;
                $isComplete = $newPicked >= (float) $task->quantity_to_pick;

                $log = is_array($task->scan_log) ? $task->scan_log : [];
                $log[] = [
                    'op_seq' => $opSeq,
                    'actor_id' => $userId,
                    'action' => 'confirm_location',
                    'location' => $scan['location'],
                    'product' => $scan['product'],
                    'quantity' => $alloc,
                    'result' => 'ok',
                    'at' => $now->toIso8601String(),
                ];

                $task->update([
                    'quantity_picked' => $newPicked,
                    'status' => $isComplete ? 'picked' : 'picking',
                    'picked_at' => $isComplete ? $now : $task->picked_at,
                    'claimed_by' => $task->claimed_by ?? $userId,
                    'claimed_at' => $task->claimed_at ?? $now,
                    'op_seq' => max($opSeq, (int) $task->op_seq),
                    'scan_log' => $log,
                ]);

                $fItem = $task->fulfillmentItem;
                if ($fItem) {
                    $fItem->increment('quantity_picked', $alloc);
                    $freshItem = $fItem->fresh();
                    $itemComplete = (float) $freshItem->quantity_picked >= (float) $freshItem->quantity;
                    $freshItem->update([
                        'status' => $itemComplete ? 'picked' : 'picking',
                        'picked_at' => $itemComplete ? $now : $freshItem->picked_at,
                    ]);
                }

                $qtyToAllocate -= $alloc;
                $updatedTasks[] = $task->fresh();
            }

            // 6. Refresh batch progress (auto-advances fulfillments if all tasks completed)
            $refreshedBatch = $this->refreshBatchProgress($batch->fresh());
            $nextTask = $this->getNextTask($batch->fresh());

            Log::info('Consolidated location pick confirmed', [
                'batch_id' => $batch->id,
                'location_id' => $locationId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'tasks_count' => count($updatedTasks),
                'actor_id' => $userId,
            ]);

            return [
                'batch_id' => $batch->id,
                'batch_status' => $refreshedBatch->status,
                'location_barcode' => (string) $scan['location'],
                'scanned_product' => (string) $scan['product'],
                'total_picked_now' => $quantity,
                'remaining_at_location' => max(0, $totalRemaining - $quantity),
                'tasks_completed_count' => collect($updatedTasks)->where('status', 'picked')->count(),
                'updated_task_ids' => collect($updatedTasks)->pluck('id')->all(),
                'next_task' => $nextTask ? \App\Http\Resources\Wms\PickingTaskResource::make($nextTask) : null,
            ];
        });
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

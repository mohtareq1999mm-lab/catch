<?php

namespace App\Services\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\PickingTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8: single-order picking — standalone tasks (batch_id NULL) on the one
 * task model. Confirmation itself lives in PickingExecutionService (shared).
 */
class OrderPickingService
{
    public function __construct(
        private FulfillmentTransition $transitions,
    ) {}

    /**
     * Create one pending task per unpicked fulfillment item that has a
     * location allocation. Idempotent: existing open tasks are reused.
     *
     * @return PickingTask[]
     */
    public function createTasksForFulfillment(Fulfillment $fulfillment): array
    {
        return DB::transaction(function () use ($fulfillment) {
            // P4-9: serialize concurrent creators. The fulfillment row lock
            // orders competing transactions; the per-item row lock makes the
            // open-task check-then-create deterministic (second caller blocks
            // until the first commits, then reuses the committed task).
            $lockedFulfillment = Fulfillment::whereKey($fulfillment->id)->lockForUpdate()->firstOrFail();

            if (!in_array($lockedFulfillment->status, ['pending', 'picking'], true)) {
                throw new \RuntimeException(
                    __('wms.fulfillment.invalid_transition', [
                        'from' => $lockedFulfillment->status,
                        'to' => 'picking',
                        'id' => $lockedFulfillment->id,
                    ])
                );
            }

            $tasks = [];
            // PICK-OBS: items without a location allocation are skipped by
            // design (manual assign-placement required) — counted here so an
            // empty result is diagnosable as "awaiting placement" instead of
            // looking like a silent failure. No response/state change.
            $skippedUnallocated = 0;
            $skippedItemIds = [];
            $totalItems = 0;

            foreach ($lockedFulfillment->items()->orderBy('id')->get() as $item) {
                $lockedItem = FulfillmentItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
                $totalItems++;
                if ($lockedItem->product_location_id === null) {
                    $skippedUnallocated++;
                    $skippedItemIds[] = $lockedItem->id;
                    continue; // manual assignment required (allocation fallback)
                }
                $remaining = (float) $lockedItem->quantity - (float) $lockedItem->quantity_picked;
                if ($remaining <= 0) {
                    continue;
                }

                // Phase 9: an item with ANY open task (order OR batch) must not
                // gain a second one — prevents double-picking across flows.
                $existing = PickingTask::where('fulfillment_item_id', $lockedItem->id)
                    ->whereIn('status', ['pending', 'assigned', 'picking'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    $tasks[] = $existing;
                    continue;
                }

                $tasks[] = PickingTask::create([
                    'batch_id' => null,
                    'fulfillment_item_id' => $lockedItem->id,
                    'product_location_id' => $lockedItem->product_location_id,
                    'order_id' => $lockedFulfillment->order_id,
                    'order_item_id' => $lockedItem->order_item_id,
                    'quantity_to_pick' => $remaining,
                    'quantity_picked' => 0,
                    'status' => 'pending',
                    'sequence' => count($tasks) + 1,
                ]);
            }

            // P4-1: picking work now exists for this fulfillment — move
            // pending → picking through the canonical owner (batch parity).
            // Only from pending; every other state is left for its own flow.
            if (count($tasks) > 0 && $lockedFulfillment->status === 'pending') {
                $this->transitions->transition($lockedFulfillment, 'picking', ['reason' => 'order_picking_started']);
            }

            if (count($tasks) > 0) {
                app(\App\Services\Fulfillment\FulfillmentOrderSyncService::class)
                    ->announce((int) $lockedFulfillment->order_id, 'picking_started', (int) $lockedFulfillment->id);
            }

            Log::info('Order picking tasks created', [
                'fulfillment_id' => $fulfillment->id,
                'tasks' => count($tasks),
                'skipped_unallocated' => $skippedUnallocated,
                'skipped_item_ids' => $skippedItemIds,
            ]);

            // Partial omission diagnosability: some items remain without
            // placement while others produced tasks. The 200 carries the
            // created subset (envelope preserved); this warning names the
            // remainder so operators know to assign-placement for the rest.
            if (count($tasks) > 0 && $skippedUnallocated > 0) {
                Log::warning('Order picking created partial tasks; remaining items await manual placement (assign-placement)', [
                    'fulfillment_id' => $fulfillment->id,
                    'order_id' => $lockedFulfillment->order_id,
                    'skipped_unallocated' => $skippedUnallocated,
                    'skipped_item_ids' => $skippedItemIds,
                ]);
            }

            // WMS-PICK-001: never return an empty success. An empty result
            // means no pickable work exists — surface it as a domain refusal
            // (422 at the adapter) with an actionable reason instead of
            // "Picking tasks ready." with data:[].
            if (count($tasks) === 0) {
                if ($totalItems === 0) {
                    throw new \RuntimeException(
                        __('wms.picking.empty', ['id' => $lockedFulfillment->id])
                    );
                }
                if ($skippedUnallocated > 0) {
                    Log::warning('Order picking refused: items await manual placement (assign-placement)', [
                        'fulfillment_id' => $fulfillment->id,
                        'order_id' => $lockedFulfillment->order_id,
                        'skipped_unallocated' => $skippedUnallocated,
                        'skipped_item_ids' => $skippedItemIds,
                    ]);
                    throw new \RuntimeException(
                        __('wms.picking.awaiting_placement', ['id' => $lockedFulfillment->id, 'count' => $skippedUnallocated])
                    );
                }
                throw new \RuntimeException(
                    __('wms.picking.no_tasks', ['id' => $lockedFulfillment->id])
                );
            }

            return $tasks;
        });
    }

    /**
     * P4-1: explicit order-flow picking completion (picking → picked) via
     * the canonical transition owner. Task-level `picked` is progress only —
     * this call is the explicit operation that advances the fulfillment.
     * Verifies every item is fully picked first (partial work throws);
     * repeat calls are safe via the owner's idempotent same-state no-op.
     * Never touches orders, inventory, payment, or shipment.
     *
     * @throws \RuntimeException on incomplete work or illegal transition.
     */
    public function completePicking(Fulfillment $fulfillment, array $context = []): Fulfillment
    {
        return DB::transaction(function () use ($fulfillment, $context) {
            $locked = Fulfillment::whereKey($fulfillment->id)->lockForUpdate()->firstOrFail();

            $incomplete = $locked->items()
                ->whereColumn('quantity_picked', '<', 'quantity')
                ->exists();
            if ($incomplete) {
                throw new \RuntimeException(
                    __('wms.fulfillment.incomplete_picking', ['id' => $locked->id])
                );
            }

            $picked = $this->transitions->transition(
                $locked, 'picked', $context + ['reason' => $context['reason'] ?? 'order_picking_completed']
            );

            app(\App\Services\Fulfillment\FulfillmentOrderSyncService::class)
                ->announce((int) $picked->order_id, 'picked', (int) $picked->id);

            return $picked;
        });
    }
}

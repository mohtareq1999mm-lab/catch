<?php

namespace App\Services\Fulfillment;

use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\ReturnItem;
use Illuminate\Support\Facades\DB;

/**
 * Domain authority for emptying a Location of its placement hints.
 *
 * Semantics (locked):
 * - ProductLocation rows are NON-AUTHORITATIVE placement hints. Emptying
 *   only deletes hint rows; it NEVER writes products.stock_quantity,
 *   products.reserved_quantity, or order inventory state.
 * - Eligible rows are zero-quantity hints (quantity == 0 AND
 *   allocated_hint == 0) with no protected references. If ANY row holds
 *   physical stock, the whole operation is refused (all-or-nothing) so
 *   physical traceability is never silently lost — transfer/consume first.
 * - Protected references:
 *   (a) OPEN picking tasks (pending/assigned/picking) pin the bin;
 *   (b) unpicked fulfillment commitments (quantity_picked < quantity on a
 *   pending/picking fulfillment — mirrors StockOperationService transfer
 *   guards §E; intentionally narrow: post-pick states have goods in hand)
 *   pin the bin even before a task exists;
 *   (c) ANY picking task history (any status) blocks deletion: the
 *   picking_tasks.product_location_id FK is RESTRICT and history is audit;
 *   (d) ACTIVE returns (return_items.product_location_id pinned with
 *   restocked_at IS NULL) block deletion; COMPLETED returns are history
 *   rows (FK SET NULL) — the row survives with a nulled FK, same as
 *   terminal fulfillment_items.
 * - fulfillment_items / terminal return_items FKs are SET NULL: terminal
 *   rows are nulled by the delete, active commitments/returns are blocked
 *   by (b)/(d) above.
 * - The Location row itself is never deleted or deactivated by this
 *   operation; status/type/warehouse_id are untouched.
 * - Atomic + idempotent: one DB transaction, deterministic lock order
 *   (location -> placements -> tasks/commitments/returns, all
 *   lockForUpdate), locked rechecks make the TOCTOU window authoritative.
 *   Remaining is recounted inside the transaction before commit.
 *   Repeating on an empty location returns removed=0 with 200.
 */
class LocationEmptyService
{
    /** Real open picking set (mirrors destroy/transfer guards). */
    public const OPEN_TASK_STATUSES = ['pending', 'assigned', 'picking'];

    /** Fulfillment states that still own the bin (pre-pick-complete). */
    public const ACTIVE_FULFILLMENT_STATUSES = ['pending', 'picking'];

    /**
     * Empty the location of all eligible placements.
     *
     * @return array{location_id:int,warehouse_id:int,removed:int,removed_ids:array<int>,remaining:int}
     *
     * @throws \InvalidArgumentException with a translated wms.location.* message on unsafe.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException when the location vanished under lock.
     */
    public function empty(Location $location): array
    {
        return DB::transaction(function () use ($location) {
            $locked = Location::whereKey($location->id)->lockForUpdate()->firstOrFail();

            $placements = ProductLocation::where('location_id', $locked->id)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if ($placements->isEmpty()) {
                return [
                    'location_id' => (int) $locked->id,
                    'warehouse_id' => (int) $locked->warehouse_id,
                    'removed' => 0,
                    'removed_ids' => [],
                    'remaining' => 0,
                ];
            }

            $ids = $placements->pluck('id')->map(fn ($v) => (int) $v)->all();

            // 1. Physical-stock guard (all-or-nothing): any live hint refuses.
            $withStock = $placements->first(
                fn (ProductLocation $p) => (float) $p->quantity > 0 || (float) $p->allocated_hint > 0
            );
            if ($withStock !== null) {
                throw new \InvalidArgumentException(
                    __('wms.location.empty_stock', ['location' => $locked->id])
                );
            }

            // 2. Open picking tasks pin the bin (locked read is authoritative).
            $openTask = PickingTask::whereIn('product_location_id', $ids)
                ->whereIn('status', self::OPEN_TASK_STATUSES)
                ->lockForUpdate()
                ->first();
            if ($openTask !== null) {
                throw new \InvalidArgumentException(
                    __('wms.location.empty_tasks', ['id' => $locked->id])
                );
            }

            // 3. Pre-task commitment window: an unpicked fulfillment item
            // pinned to one of these placements still owns the bin.
            // Locked read (M1): assign-placement inserts/updates
            // fulfillment_items between our check and delete would otherwise
            // phantom through and be silently SET NULLd.
            $commitment = FulfillmentItem::whereIn('product_location_id', $ids)
                ->whereColumn('quantity_picked', '<', 'quantity')
                ->whereHas(
                    'fulfillment',
                    fn ($q) => $q->whereIn('status', self::ACTIVE_FULFILLMENT_STATUSES)
                )
                ->lockForUpdate()
                ->first();
            if ($commitment !== null) {
                throw new \InvalidArgumentException(
                    __('wms.location.empty_commitment', ['id' => $locked->id])
                );
            }

            // 3b. Active returns pin the bin: a return_item still awaiting
            // restock (restocked_at IS NULL) references this placement.
            // Completed returns are history (SET NULL nullifies the FK but
            // preserves the row) and do not block.
            $activeReturn = ReturnItem::whereIn('product_location_id', $ids)
                ->whereNull('restocked_at')
                ->lockForUpdate()
                ->first();
            if ($activeReturn !== null) {
                throw new \InvalidArgumentException(
                    __('wms.location.empty_return', ['id' => $locked->id])
                );
            }

            // 4. Picking history (any status): FK RESTRICT would 500 — refuse
            // cleanly and point at deactivation (history-preserving path).
            $history = PickingTask::whereIn('product_location_id', $ids)
                ->lockForUpdate()
                ->first();
            if ($history !== null) {
                throw new \InvalidArgumentException(
                    __('wms.location.empty_history')
                );
            }

            ProductLocation::whereIn('id', $ids)->delete();

            // Recounted inside the transaction (S6): a phantom insert racing
            // between our initial get() and commit would surface here instead
            // of a hardcoded 0.
            $remaining = ProductLocation::where('location_id', $locked->id)->count();

            return [
                'location_id' => (int) $locked->id,
                'warehouse_id' => (int) $locked->warehouse_id,
                'removed' => count($ids),
                'removed_ids' => array_values($ids),
                'remaining' => (int) $remaining,
            ];
        });
    }
}

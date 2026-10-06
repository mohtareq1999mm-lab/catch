<?php

namespace App\Services\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Services\Warehouse\WarehouseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Enums\ItemType;

class FulfillmentService
{
    public function __construct(
        private ProductLocationService $productLocationService,
        private FulfillmentTransition $transitions,
        private WarehouseService $warehouses,
        private BatchPickingService $batches,
        private PackingService $packing,
    ) {}

    /**
     * Phase 3: deterministic idempotency key for AUTOMATIC releases
     * (payment-success / COD-placement triggers). Stable per order, so any
     * replay of the same automatic trigger reuses the one fulfillment row.
     * Namespace `auto-release-order-` is reserved for this mechanism —
     * operator/explicit split keys must not use it (UNIQUE backstop stays).
     */
    public static function automaticReleaseKey(int $orderId): string
    {
        return 'auto-release-order-' . $orderId;
    }

    /**
     * Phase 6: release an order to the warehouse (Release-to-Warehouse rule).
     *
     * Releasable states (locked):
     * - capture methods (online + future gateways): inventory COMMITTED +
     *   payment success (commit and capture coincide in the callback).
     * - deferred methods (cod, pay_at_cashier): inventory COMMITTED
     *   (commit-at-creation rule; ACTIVE accepted for legacy orders) +
     *   payment pending (capture happens at delivery / mark-paid).
     * Cancelled/delivered orders never release.
     *
     * Idempotency: with $idempotencyKey, duplicate keys return the existing
     * row (safe retries, split via distinct keys). Without a key, an existing
     * PENDING fulfillment for (order, warehouse) is returned instead of
     * creating a duplicate; explicit splits pass distinct keys.
     *
     * @throws \RuntimeException when the order is not releasable.
     */
    public function releaseForOrder(Order $order, ?int $warehouseId = null, ?string $idempotencyKey = null): Fulfillment
    {
        // Single transaction: idempotency check + order lock + pending reuse
        // + creation are atomic (lockForUpdate only holds inside a txn).
        // The UNIQUE(idempotency_key) index is the final backstop for races.
        $fulfillment = DB::transaction(function () use ($order, $warehouseId, $idempotencyKey) {
            if ($idempotencyKey !== null) {
                $existing = Fulfillment::where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertReleasable($fresh);

            $warehouse = $this->warehouses->resolveForNewFulfillment($warehouseId);
            $resolvedWarehouseId = $warehouse->id;

            if ($idempotencyKey === null) {
                // P2-3: single-warehouse fulfillment — a normal (keyless)
                // release must not open a second warehouse for an order that
                // already has a non-cancelled fulfillment elsewhere. Keyed
                // calls are explicit split intent (split via distinct keys)
                // and bypass this guard. The order row lock above serializes
                // concurrent releases, so this check cannot race.
                $foreign = Fulfillment::where('order_id', $fresh->id)
                    ->where('status', '!=', 'cancelled')
                    ->where('warehouse_id', '!=', $resolvedWarehouseId)
                    ->lockForUpdate()
                    ->first();
                if ($foreign) {
                    throw new \RuntimeException(
                        "Order #{$fresh->id} already has fulfillment #{$foreign->id} " .
                        "in warehouse {$foreign->warehouse_id}: second normal fulfillment " .
                        "in warehouse {$resolvedWarehouseId} rejected (use an explicit split key)"
                    );
                }

                $pending = Fulfillment::where('order_id', $fresh->id)
                    ->where('warehouse_id', $resolvedWarehouseId)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();
                if ($pending) {
                    return $pending;
                }
            }

            $created = $this->createFromOrder($fresh, $resolvedWarehouseId);
            if ($idempotencyKey !== null) {
                $created->update(['idempotency_key' => $idempotencyKey]);
            }

            return $created->fresh();
        });

        Log::info('Fulfillment released to warehouse', [
            'fulfillment_id' => $fulfillment->id,
            'order_id' => $fulfillment->order_id,
            'warehouse_id' => $fulfillment->warehouse_id,
            'idempotency_key' => $idempotencyKey,
        ]);

        return $fulfillment;
    }

    /**
     * @throws \RuntimeException
     */
    private function assertReleasable(Order $order): void
    {
        if (in_array($order->status, ['cancelled', 'delivered'], true)) {
            throw new \RuntimeException("Order #{$order->id} is {$order->status}: cannot release to warehouse");
        }

        $deferred = in_array($order->payment_method, ['cod', 'pay_at_cashier'], true);
        $paid = $order->payment_status === Order::PAYMENT_STATUS_SUCCESS;

        if ($deferred) {
            // Capture happens later (delivery / mark-paid). Phase 3
            // addendum: COD/cashier commit at creation, so COMMITTED is the
            // normal releasable state; ACTIVE is still accepted for legacy
            // orders created before the commit-at-creation rule. Cancelled /
            // delivered are refused above; anything else fails closed.
            if (!in_array($order->inventory_state, [Order::INVENTORY_STATE_ACTIVE, Order::INVENTORY_STATE_COMMITTED], true)) {
                throw new \RuntimeException(
                    "COD order #{$order->id} has no secured inventory (state: {$order->inventory_state})"
                );
            }

            return;
        }

        // Capture methods: payment success and inventory commit coincide, so
        // the releasable state is COMMITTED + paid (never active + paid).
        if (!$paid || $order->inventory_state !== Order::INVENTORY_STATE_COMMITTED) {
            throw new \RuntimeException(
                "Order #{$order->id} is not captured (payment: {$order->payment_status}, inventory: {$order->inventory_state})"
            );
        }
    }

    /**
     * Create fulfillment from order
     */
    public function createFromOrder(Order $order, ?int $warehouseId = null): Fulfillment
    {
        return DB::transaction(function () use ($order, $warehouseId) {
            // Explicit selection wins; otherwise the ACTIVE default is used.
            // Inactive warehouses are rejected; existing fulfillments continue
            // after a later deactivation (creation-time gate only).
            $warehouse = $this->warehouses->resolveForNewFulfillment($warehouseId);
            $warehouseId = $warehouse->id;

            // Generate fulfillment number
            $fulfillmentNumber = $this->generateFulfillmentNumber();

            // Create fulfillment record. The warehouse identity snapshot
            // (code/name) is write-once history: set via forceFill so later
            // mass-assignment can never rewrite it; deletes are restricted
            // while fulfillments reference the row.
            $fulfillment = Fulfillment::create([
                'order_id' => $order->id,
                'warehouse_id' => $warehouseId,
                'fulfillment_number' => $fulfillmentNumber,
                'status' => 'pending',
                'priority' => $this->determinePriority($order),
            ]);
            $fulfillment->forceFill([
                'warehouse_code' => $warehouse->code,
                'warehouse_name' => $warehouse->name,
            ])->save();

            // Create fulfillment items with location allocation — physical
            // lines ONLY (D1 digital filter, mirroring
            // OrderReservationService::aggregatePhysicalLines: digital lines
            // hold no reservation and must never enter physical WMS, or
            // picking completion (which requires every item picked) could
            // never be reached for mixed orders).
            $order->loadMissing('orderItems.product');
            foreach ($order->orderItems as $orderItem) {
                if ($this->isDigitalOrderLine($orderItem)) {
                    continue;
                }
                $this->createFulfillmentItem(
                    $fulfillment,
                    $orderItem,
                    $warehouseId
                );
            }

            // No fake fulfillments: a release with zero physical lines is a
            // caller error (digital-only orders are refused by the release
            // guards before reaching here). Throwing inside this transaction
            // rolls back the header row as well — nothing persists.
            if ($fulfillment->items()->count() === 0) {
                throw new \RuntimeException(
                    "Order #{$order->id} has no physical lines: refusing empty fulfillment"
                );
            }

            Log::info('Fulfillment created from order', [
                'fulfillment_id' => $fulfillment->id,
                'fulfillment_number' => $fulfillmentNumber,
                'order_id' => $order->id,
                'warehouse_id' => $warehouseId,
                'items_count' => $fulfillment->items()->count(),
            ]);

            return $fulfillment->load('items.productLocation');
        });
    }

    /**
     * D1 digital-line predicate for fulfillment creation. Mirrors the
     * reservation authority (OrderReservationService::aggregatePhysicalLines)
     * exactly: an explicit DIGITAL item_type on the order line wins,
     * otherwise the product's item_type decides (legacy rows without the
     * order_products.item_type column fall back to the product).
     */
    private function isDigitalOrderLine($orderItem): bool
    {
        if (Schema::hasColumn('order_products', 'item_type')
            && ($orderItem->item_type ?? null) === ItemType::DIGITAL
        ) {
            return true;
        }

        $product = $orderItem->product;
        if ($product && (($product->item_type ?? ItemType::PHYSICAL) === ItemType::DIGITAL)) {
            return true;
        }

        return false;
    }

    /**
     * Create fulfillment item with location allocation
     */
    private function createFulfillmentItem(
        Fulfillment $fulfillment,
        $orderItem,
        int $warehouseId
    ): void {
        $productId = $orderItem->product_id;
        $quantity = $orderItem->product_quantity ?? $orderItem->order_quantity ?? 0;

        // Allocate from locations
        try {
            $allocations = $this->productLocationService->allocateFromLocations(
                $productId,
                $quantity,
                $warehouseId
            );

            // Create fulfillment item for each allocation. NOTE: this must
            // store the ProductLocation row id (product_location_id), never
            // the Location id — they are different tables.
            foreach ($allocations as $allocation) {
                FulfillmentItem::create([
                    'fulfillment_id' => $fulfillment->id,
                    'order_item_id' => $orderItem->id,
                    'product_id' => $productId,
                    'product_variant_id' => $orderItem->variation_option_id,
                    'product_location_id' => $allocation['product_location_id'],
                    'quantity' => $allocation['allocated'],
                    'quantity_picked' => 0,
                    'status' => 'pending',
                ]);
            }
        } catch (\Exception $e) {
            // Placement/warehouse corruption must never degrade silently into
            // a routine stockout: surface it at error level for ops.
            $level = str_starts_with($e->getMessage(), 'PLACEMENT_MISMATCH:') ? 'error' : 'warning';
            Log::log($level, 'Failed to allocate location for fulfillment item', [
                'fulfillment_id' => $fulfillment->id,
                'product_id' => $productId,
                'quantity' => $quantity,
                'error' => $e->getMessage(),
            ]);

            FulfillmentItem::create([
                'fulfillment_id' => $fulfillment->id,
                'order_item_id' => $orderItem->id,
                'product_id' => $productId,
                'product_variant_id' => $orderItem->variation_option_id,
                'product_location_id' => null,
                'quantity' => $quantity,
                'quantity_picked' => 0,
                'status' => 'pending',
                'notes' => 'Location allocation failed: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Update fulfillment status — delegated to the single transition owner.
     * Kept as a thin wrapper for compatibility; new code should call
     * FulfillmentTransition directly.
     */
    public function updateStatus(Fulfillment $fulfillment, string $status): Fulfillment
    {
        return $this->transitions->transition($fulfillment, $status);
    }

    /**
     * Phase 12: operational cancellation of a fulfillment (e.g. order cancelled
     * mid-pick). Cancels the fulfillment via the single owner, skips open
     * picking tasks (picked progress stays on items for audit), cancels open
     * packing tasks. Inventory/coupon release is owned by the order cancel
     * path (changeOrderStatus) — never duplicated here.
     */
    /**
     * Cancel a fulfillment operationally.
     *
     * Phase 7 guards (all decided on the locked fresh row BEFORE the
     * status transition, so a refusal never leaves a half-cancelled row):
     * - P7-4: a live (non-cancelled) shipment refuses cancellation loudly.
     *   Phase 8 owns shipment cancellation; this service never mutates it.
     * - P7-6: sealed packages are physical custody — refusal, never silent
     *   void. Packed packing tasks refuse too (Phase-6 cancelTask parity);
     *   the mass update below additionally excludes packed as a race
     *   backstop and reports leftovers instead of cancelling them.
     * - P7-3: skipped picking tasks have their picker claims cleared
     *   (BatchPickingService::cancelBatch parity).
     * - P7-8: cancelled_by / cancel_source are recorded (nullable actor,
     *   explicit source; never fabricated).
     *
     * @param array{cancel_source?: string, cancelled_by?: int|null, actor_type?: string} $context
     */
    public function cancelFulfillment(Fulfillment $fulfillment, string $reason, array $context = []): Fulfillment
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Cancellation reason is required');
        }

        return DB::transaction(function () use ($fulfillment, $reason, $context) {
            // Locked fresh read first: every guard below decides on locked
            // state. The fulfillment lock also serializes concurrent packing
            // task / package creators (they lock this row to create).
            $locked = Fulfillment::whereKey($fulfillment->id)->lockForUpdate()->firstOrFail();

            // P7-4: live-shipment boundary.
            $liveShipment = \App\Models\Shipment::where('fulfillment_id', $locked->id)
                ->where('status', '!=', 'cancelled')
                ->lockForUpdate()
                ->exists();
            if ($liveShipment) {
                throw new \RuntimeException(
                    "Cannot cancel fulfillment #{$locked->id}: a live shipment exists " .
                    '(shipment cancellation belongs to Phase 8)'
                );
            }

            // P7-6: sealed custody refuses loudly (open packages keep the
            // Phase-6 voidPackage authority below).
            $sealed = \App\Models\Fulfillment\Package::where('fulfillment_id', $locked->id)
                ->where('status', \App\Models\Fulfillment\Package::STATUS_SEALED)
                ->lockForUpdate()
                ->exists();
            if ($sealed) {
                throw new \RuntimeException(
                    "Cannot cancel fulfillment #{$locked->id}: sealed packages exist (physical custody)"
                );
            }

            // P7-6: packed-task parity with cancelTask (packed is terminal
            // for cancellation). The mass update below excludes packed as a
            // race backstop; leftovers are reported, never cancelled.
            $packed = \App\Models\Fulfillment\PackingTask::where('fulfillment_id', $locked->id)
                ->where('status', 'packed')
                ->lockForUpdate()
                ->exists();
            if ($packed) {
                throw new \RuntimeException(
                    "Cannot cancel fulfillment #{$locked->id}: packed packing tasks exist (verify first)"
                );
            }

            $transitionContext = ['reason' => $reason];
            if (array_key_exists('cancelled_by', $context) && $context['cancelled_by'] !== null) {
                $transitionContext['actor_id'] = $context['cancelled_by'];
            } elseif (auth()->check()) {
                $transitionContext['actor_id'] = auth()->id();
            }
            if (array_key_exists('actor_type', $context) && $context['actor_type'] !== null) {
                $transitionContext['actor_type'] = $context['actor_type'];
            }
            $cancelled = $this->transitions->transition(
                $locked, 'cancelled', $transitionContext
            );

            // P7-8: explicit cancel audit. Nullable actor (system/internal
            // calls carry no user); explicit source, default 'direct'.
            $cancelled->update([
                'cancelled_by' => $context['cancelled_by'] ?? (auth()->check() ? auth()->id() : null),
                'cancel_source' => $context['cancel_source'] ?? 'direct',
            ]);

            // P5-3: no orphaned active batch. Parent batches are cancelled
            // FIRST through the batch authority — cancelBatch skips open
            // tasks and clears their claims (P4-4), so the fulfillment task
            // update below only handles remaining order-flow tasks. Picked
            // work is untouched; terminal batches are skipped via cancelBatch's
            // own guard, so repeats are safe.
            $itemIds = $cancelled->items()->pluck('id');
            $parentBatchIds = \App\Models\Fulfillment\PickingTask::whereIn('fulfillment_item_id', $itemIds)
                ->whereNotNull('batch_id')
                ->distinct()
                ->pluck('batch_id');
            foreach ($parentBatchIds as $parentBatchId) {
                $parent = \App\Models\Fulfillment\FulfillmentBatch::whereKey($parentBatchId)->first();
                if ($parent === null || in_array($parent->status, ['completed', 'cancelled'], true)) {
                    continue;
                }
                $this->batches->cancelBatch($parent, 'Fulfillment cancelled: ' . $reason);
            }

            if ($itemIds->isNotEmpty()) {
                // P7-3: clear picker claims exactly as cancelBatch does —
                // a skipped task must not retain an operational claim.
                // Picked quantities stay (physical truth); history stays.
                \App\Models\Fulfillment\PickingTask::whereIn('fulfillment_item_id', $itemIds)
                    ->whereIn('status', ['pending', 'assigned', 'picking'])
                    ->update([
                        'status' => 'skipped',
                        'notes' => 'Fulfillment cancelled: ' . $reason,
                        'claimed_by' => null,
                        'claimed_at' => null,
                        'claim_expires_at' => null,
                    ]);
            }

            // P7-6: packed excluded (guard above refused when packed exist;
            // this is the concurrent-verify race backstop). Leftovers are
            // reported, never force-cancelled.
            \App\Models\Fulfillment\PackingTask::where('fulfillment_id', $cancelled->id)
                ->whereNotIn('status', ['packed', 'verified', 'cancelled'])
                ->update(['status' => 'cancelled', 'notes' => 'Fulfillment cancelled: ' . $reason]);

            $leftoverPacked = \App\Models\Fulfillment\PackingTask::where('fulfillment_id', $cancelled->id)
                ->where('status', 'packed')
                ->exists();
            if ($leftoverPacked) {
                Log::warning('Cancelled fulfillment retains packed tasks (concurrent verify won the race)', [
                    'fulfillment_id' => $cancelled->id,
                ]);
            }

            // P6-7: open packages are voided through the packing authority
            // (rows retained for audit; sealed custody refused above).
            $this->packing->voidOpenPackagesForFulfillment($cancelled, 'Fulfillment cancelled: ' . $reason);

            Log::warning('Fulfillment cancelled operationally', [
                'fulfillment_id' => $cancelled->id,
                'order_id' => $cancelled->order_id,
                'reason' => $reason,
                'cancel_source' => $cancelled->cancel_source,
                'cancelled_by' => $cancelled->cancelled_by,
            ]);

            return $cancelled->fresh();
        });
    }

    /**
     * Assign fulfillment to user. Phase 1 (T1): first-winner-wins under row lock.
     * NULL assignee → assign; same user → idempotent success; another user →
     * controlled conflict (never silently overwrite another assignment).
     */
    public function assignToUser(Fulfillment $fulfillment, int $userId): Fulfillment
    {
        return DB::transaction(function () use ($fulfillment, $userId) {
            $locked = Fulfillment::whereKey($fulfillment->id)->lockForUpdate()->firstOrFail();

            if ($locked->assigned_to !== null && (int) $locked->assigned_to !== $userId) {
                throw new \RuntimeException(
                    "Fulfillment #{$locked->id} is already assigned to user {$locked->assigned_to}"
                );
            }

            $locked->update(['assigned_to' => $userId]);

            Log::info('Fulfillment assigned to user', [
                'fulfillment_id' => $locked->id,
                'user_id' => $userId,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Phase 1 (T2): assign a placement hint to a NULL-allocation fulfillment item
     * so the existing picking-task flow can continue.
     *
     * Guards (all on locked rows): item unpicked and open-task-free; target holds
     * the same product; target is in the fulfillment warehouse; target location is
     * active + placeable; placement/warehouse drift rejected.
     *
     * Inventory authority preserved: NEVER writes products.stock_quantity,
     * products.reserved_quantity, product_locations.quantity, or
     * product_locations.allocated_hint — only the item's product_location_id.
     *
     * @throws \RuntimeException on any guard violation.
     */
    public function assignPlacement(FulfillmentItem $item, int $productLocationId): FulfillmentItem
    {
        return DB::transaction(function () use ($item, $productLocationId) {
            $locked = FulfillmentItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ((float) $locked->quantity_picked >= (float) $locked->quantity) {
                throw new \RuntimeException(
                    "Cannot assign placement: fulfillment item #{$locked->id} is already fully picked"
                );
            }

            $openTask = \App\Models\Fulfillment\PickingTask::where('fulfillment_item_id', $locked->id)
                ->whereIn('status', ['pending', 'assigned', 'picking'])
                ->lockForUpdate()
                ->first();
            if ($openTask) {
                throw new \RuntimeException(
                    "Cannot assign placement: fulfillment item #{$locked->id} already has open picking task #{$openTask->id}"
                );
            }

            $fulfillment = $locked->fulfillment()->firstOrFail();

            $target = \App\Models\Fulfillment\ProductLocation::whereKey($productLocationId)
                ->lockForUpdate()
                ->firstOrFail();
            $location = $target->location()->firstOrFail();

            if ((int) $target->product_id !== (int) $locked->product_id) {
                throw new \RuntimeException('Cannot assign placement: target holds a different product');
            }
            if ((int) $target->warehouse_id !== (int) $fulfillment->warehouse_id) {
                throw new \RuntimeException('Cannot assign placement: target is in a different warehouse');
            }
            $placeable = $location->status === \App\Models\Fulfillment\Location::STATUS_ACTIVE
                && ($location->type === null || in_array($location->type, \App\Models\Fulfillment\Location::PLACEABLE_TYPES, true));
            if (!$placeable) {
                throw new \RuntimeException("Cannot assign placement: location #{$location->id} is not active/placeable");
            }
            $this->productLocationService->assertConsistent($target);

            $locked->update(['product_location_id' => $target->id]);

            Log::info('Fulfillment item placement assigned', [
                'fulfillment_item_id' => $locked->id,
                'fulfillment_id' => $locked->fulfillment_id,
                'product_location_id' => $target->id,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Generate unique fulfillment number
     */
    private function generateFulfillmentNumber(): string
    {
        do {
            $number = 'FUL-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            $exists = Fulfillment::where('fulfillment_number', $number)->exists();
        } while ($exists);

        return $number;
    }

    /**
     * Determine priority based on order attributes
     */
    private function determinePriority(Order $order): string
    {
        // High priority: express shipping or VIP customers
        if (isset($order->metadata['shipping_method']) &&
            str_contains(strtolower($order->metadata['shipping_method']), 'express')) {
            return 'high';
        }

        // Default priority
        return 'normal';
    }
}

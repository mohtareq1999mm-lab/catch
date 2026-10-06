<?php

namespace App\Services\Shipment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Package;
use App\Models\Fulfillment\PackageItem;
use App\Models\Fulfillment\PackingTask;
use App\Models\Shipment;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\General\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;

class ShipmentService
{
    public function __construct(
        private FulfillmentTransition $fulfillmentTransitions,
        private OrderService $orderService,
    ) {}

    public function list(array $filters = [], int $perPage = 15)
    {
        return Shipment::query()
            ->with(['order'])
            ->when($filters['order_id'] ?? null, fn($q, $v) => $q->where('order_id', (int) $v))
            ->when($filters['status'] ?? null, fn($q, $v) => $q->where('status', $v))
            ->when($filters['courier'] ?? null, fn($q, $v) => $q->where('courier', $v))
            ->when($filters['tracking_number'] ?? null, fn($q, $v) => $q->where('tracking_number', 'like', "%{$v}%"))
            ->when($filters['from'] ?? null, fn($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderBy('created_at', 'desc')
            ->paginate(min($perPage, 100));
    }

    public function find(int $id): Shipment
    {
        return Shipment::with(['order'])->findOrFail($id);
    }

    public function findByUuid(string $uuid): Shipment
    {
        return Shipment::with(['order'])->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * Phase 8 (D8-3): generic creation stays fulfillment-aware. When a
     * fulfillment is linked it must exist, must not be cancelled, and must
     * not already carry an active shipment (D8-1). Unreachable via HTTP
     * today; the explicit staff path is createForFulfillment().
     */
    public function create(array $data): Shipment
    {
        return DB::transaction(function () use ($data) {
            $fulfillmentId = isset($data['fulfillment_id']) ? (int) $data['fulfillment_id'] : null;

            if ($fulfillmentId) {
                $lockedFulfillment = Fulfillment::whereKey($fulfillmentId)->lockForUpdate()->firstOrFail();
                $this->assertFulfillmentCreatable($lockedFulfillment);
                $this->assertNoActiveShipment($fulfillmentId);
                $data['order_id'] = $data['order_id'] ?? $lockedFulfillment->order_id;
            }

            if (!empty($data['order_id'])) {
                Order::whereKey((int) $data['order_id'])->lockForUpdate()->first();
            }

            $data['status'] = 'pending';
            unset($data['idempotency_key']);
            $shipment = Shipment::create($data);

            $this->syncOrderMirror($shipment->fresh());

            return $shipment->fresh();
        });
    }

    /**
     * Phase 8 (D8-1 / D8-3 / F8-10): explicit staff/dispatch creation for one
     * fulfillment. Requires fulfillment = ready_to_ship on the LOCKED fresh
     * row (stale models rechecked). Refuses cancelled fulfillments loudly.
     *
     * Idempotency is scoped to (fulfillment_id, idempotency_key): the same
     * key for the same fulfillment replays the same row; the same key for a
     * DIFFERENT fulfillment is refused loudly (never returns another
     * fulfillment's row).
     * Unkeyed creation refuses when an active shipment already exists — one
     * active shipment per fulfillment; terminal rows (cancelled / delivered /
     * returned) are retained as history and do not block a new label.
     *
     * The UNIQUE(active_fulfillment_id) backstop aborts a lost race loudly.
     *
     * @throws \RuntimeException on invalid state, duplicate, or key reuse.
     */
    public function createForFulfillment(Fulfillment $fulfillment, array $data = [], ?string $idempotencyKey = null): Shipment
    {
        $idempotencyKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : null;

        if ($idempotencyKey !== null) {
            $existing = Shipment::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->fulfillment_id !== (int) $fulfillment->id) {
                    throw new \RuntimeException(
                        "Idempotency key is already bound to shipment #{$existing->id} " .
                        "of fulfillment #{$existing->fulfillment_id}; refusing fulfillment #{$fulfillment->id}"
                    );
                }

                return $existing;
            }
        }

        return DB::transaction(function () use ($fulfillment, $data, $idempotencyKey) {
            // Lock order (D8-1 §S): Order → Fulfillment → Shipment, the same
            // direction as the order-cancel cascade, so no reverse edge.
            $orderId = (int) $fulfillment->order_id;
            if ($orderId) {
                Order::whereKey($orderId)->lockForUpdate()->first();
            }

            $locked = Fulfillment::whereKey($fulfillment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled') {
                throw new \RuntimeException(
                    "Cannot create shipment: fulfillment #{$locked->id} is cancelled"
                );
            }
            if ($locked->status !== 'ready_to_ship') {
                throw new \RuntimeException(
                    "Cannot create shipment: fulfillment #{$locked->id} is {$locked->status}, expected ready_to_ship"
                );
            }

            $this->assertNoActiveShipment($locked->id);

            $shipment = Shipment::create(array_merge([
                'order_id' => $locked->order_id,
                'fulfillment_id' => $locked->id,
                'status' => 'label_created',
                'idempotency_key' => $idempotencyKey,
            ], $data, ['status' => 'label_created', 'idempotency_key' => $idempotencyKey]));

            Log::info('Shipment created for fulfillment', [
                'shipment_id' => $shipment->id,
                'fulfillment_id' => $locked->id,
            ]);

            $this->syncOrderMirror($shipment->fresh());

            return $shipment->fresh();
        });
    }

    /**
     * Phase 8 (D8-4): dispatch (carrier handoff) in ONE transaction.
     * Lock order: Order → Fulfillment → Shipment.
     *
     * Every precondition that can reject is validated BEFORE the shipment
     * mutation, so a fulfillment/package refusal can never strand a
     * half-dispatched shipment (the old two-transaction window is gone):
     * shipment must be label_created (duplicate dispatch refuses loudly),
     * fulfillment must accept shipped on the locked fresh row, and the
     * Phase-6 packing invariant is re-verified read-only when packing work
     * exists. Goes through FulfillmentTransition — never a direct write.
     */
    public function dispatch(int $shipmentId, ?string $notes = null): Shipment
    {
        return DB::transaction(function () use ($shipmentId, $notes) {
            $probe = Shipment::findOrFail($shipmentId);

            if ((int) $probe->order_id) {
                Order::whereKey((int) $probe->order_id)->lockForUpdate()->firstOrFail();
            }

            $fulfillment = $probe->fulfillment_id
                ? Fulfillment::whereKey((int) $probe->fulfillment_id)->lockForUpdate()->first()
                : null;

            if ($fulfillment !== null && !$fulfillment->canTransitionTo('shipped')) {
                throw new \RuntimeException(
                    "Cannot dispatch shipment #{$probe->id}: fulfillment #{$fulfillment->id} " .
                    "is {$fulfillment->status}, expected ready_to_ship"
                );
            }

            if ($fulfillment !== null) {
                $this->assertDispatchablePackages($fulfillment);
            }

            $shipment = Shipment::lockForUpdate()->findOrFail($shipmentId);

            if (!$shipment->canTransitionTo('picked_up')) {
                throw new \RuntimeException(
                    "Shipment {$shipment->id} cannot dispatch from '{$shipment->status}': expected label_created"
                );
            }

            $this->writeStatus($shipment, 'picked_up', $notes);

            if ($fulfillment !== null && $fulfillment->status === 'ready_to_ship') {
                $this->fulfillmentTransitions->transition($fulfillment, 'shipped', ['reason' => 'shipment_dispatched']);
            }

            $fresh = $shipment->fresh();
            $this->syncOrderMirror($fresh);

            return $fresh;
        });
    }

    /**
     * Phase 8 (D8-4 / F8-2 / F8-3): staff-confirmed delivery in ONE
     * transaction. Lock order: Order → Fulfillment → Shipment.
     *
     * Explicit progression only: picked_up → in_transit →
     * out_for_delivery → delivered. pending / label_created /
     * failed_delivery / cancelled / returned / delayed NEVER jump to
     * delivered — they refuse loudly BEFORE any mutation. The fulfillment
     * must accept delivered on the locked fresh row BEFORE the shipment
     * moves, so no shipment state is ever advanced ahead of a fulfillment
     * transition that later rejects. Duplicate delivery of an already
     * delivered shipment is a safe idempotent no-op (completion already ran
     * atomically in the first transaction, so there is nothing to re-run).
     */
    public function markDelivered(int $shipmentId, ?string $notes = null): Shipment
    {
        return DB::transaction(function () use ($shipmentId, $notes) {
            $probe = Shipment::findOrFail($shipmentId);

            if ($probe->status === 'delivered') {
                // Idempotent replay — but also the completion recovery loop:
                // a delivery recorded while completion was blocked (payment
                // voided, fulfillment set incomplete) re-evaluates the
                // invariant here once the blocker is resolved. maybeComplete
                // Order is itself guarded (completed + paid + all delivered)
                // and never fires for an already-delivered order.
                $recovered = $this->maybeCompleteOrder((int) $probe->order_id);
                if ($recovered) {
                    Log::info('Order completed on delivery replay', [
                        'shipment_id' => $probe->id,
                        'order_id' => $probe->order_id,
                    ]);
                }

                return $probe->fresh();
            }

            if (!in_array($probe->status, ['picked_up', 'in_transit', 'out_for_delivery'], true)) {
                throw new \RuntimeException(
                    "Shipment {$probe->id} cannot be delivered from '{$probe->status}': " .
                    'expected picked_up, in_transit or out_for_delivery'
                );
            }

            if ((int) $probe->order_id) {
                Order::whereKey((int) $probe->order_id)->lockForUpdate()->firstOrFail();
            }

            $fulfillment = $probe->fulfillment_id
                ? Fulfillment::whereKey((int) $probe->fulfillment_id)->lockForUpdate()->first()
                : null;

            if ($fulfillment !== null && !$fulfillment->canTransitionTo('delivered')) {
                throw new \RuntimeException(
                    "Cannot deliver shipment #{$probe->id}: fulfillment #{$fulfillment->id} " .
                    "is {$fulfillment->status}, expected shipped"
                );
            }

            $shipment = Shipment::lockForUpdate()->findOrFail($shipmentId);

            if (!in_array($shipment->status, ['picked_up', 'in_transit', 'out_for_delivery'], true)) {
                throw new \RuntimeException(
                    "Shipment {$shipment->id} cannot be delivered from '{$shipment->status}': " .
                    'expected picked_up, in_transit or out_for_delivery'
                );
            }

            $chain = ['picked_up', 'in_transit', 'out_for_delivery', 'delivered'];
            $start = array_search($shipment->status, $chain, true);
            foreach (array_slice($chain, $start + 1) as $next) {
                if (!$shipment->canTransitionTo($next)) {
                    throw new \RuntimeException(
                        "Shipment {$shipment->id} cannot transition from '{$shipment->status}' to '{$next}'"
                    );
                }
                $this->writeStatus($shipment, $next, $notes);
            }

            if ($fulfillment !== null && $fulfillment->status !== 'delivered') {
                $this->fulfillmentTransitions->transition($fulfillment, 'delivered', ['reason' => 'shipment_delivered']);
            }

            $this->maybeCompleteOrder((int) $shipment->order_id);

            $fresh = $shipment->fresh();
            $this->syncOrderMirror($fresh);

            return $fresh;
        });
    }

    /**
     * Phase 8 (D8-2 / D8-10): canonical shipment cancellation. ONE
     * transaction, lock order Order → Shipment (+ Fulfillment when linked).
     *
     * Requires a non-empty reason; records actor/source per the Phase-7
     * convention (nullable actor, explicit source, default 'direct', never
     * fabricated) plus cancelled_at. Goes through the DAG — duplicate or
     * terminal-state cancellation refuses loudly. A shipped/delivered
     * fulfillment refuses: cancellation never moves fulfillment backward.
     * Never writes orders.status — Order Flow stays authoritative.
     *
     * @param array{cancelled_by?: int|null, cancel_source?: string} $context
     */
    public function cancelShipment(int $shipmentId, string $reason, array $context = []): Shipment
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Shipment cancellation reason is required');
        }

        return DB::transaction(function () use ($shipmentId, $reason, $context) {
            $probe = Shipment::findOrFail($shipmentId);

            if ((int) $probe->order_id) {
                Order::whereKey((int) $probe->order_id)->lockForUpdate()->firstOrFail();
            }

            $shipment = Shipment::lockForUpdate()->findOrFail($shipmentId);

            if (!$shipment->canTransitionTo('cancelled')) {
                throw new \RuntimeException(
                    "Shipment {$shipment->id} cannot be cancelled from '{$shipment->status}'"
                );
            }

            if ($shipment->fulfillment_id) {
                $fulfillment = Fulfillment::whereKey((int) $shipment->fulfillment_id)->lockForUpdate()->first();
                if ($fulfillment !== null && in_array($fulfillment->status, ['shipped', 'delivered'], true)) {
                    throw new \RuntimeException(
                        "Cannot cancel shipment #{$shipment->id}: fulfillment #{$fulfillment->id} " .
                        "is already {$fulfillment->status} (cancellation never moves fulfillment backward)"
                    );
                }
            }

            $shipment->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancel_reason' => trim($reason),
                'cancelled_by' => $context['cancelled_by'] ?? (auth()->check() ? auth()->id() : null),
                'cancel_source' => $context['cancel_source'] ?? 'direct',
                'notes' => $shipment->notes,
            ]);

            Log::warning('Shipment cancelled', [
                'shipment_id' => $shipment->id,
                'order_id' => $shipment->order_id,
                'fulfillment_id' => $shipment->fulfillment_id,
                'reason' => trim($reason),
                'cancel_source' => $shipment->fresh()->cancel_source,
                'cancelled_by' => $shipment->fresh()->cancelled_by,
            ]);

            $fresh = $shipment->fresh();
            $this->syncOrderMirror($fresh);

            return $fresh;
        });
    }

    /**
     * Order completion rule (locked §22): completed + payment-success + every
     * fulfillment delivered → delivered. Otherwise the order stays as-is.
     *
     * LOCKING CONTRACT: the guard reads below are unlocked snapshots. This
     * is safe because every current caller holds the order lock (delivery
     * paths run inside their shipment transaction) and the actual mutation
     * re-locks inside OrderService::changeOrderStatus. A future caller
     * without the order lock MUST take it (or accept a check-then-act race
     * window) — do not call this from lock-free scheduler/listener code
     * without holding Order::lockForUpdate() first.
     */
    public function maybeCompleteOrder(int $orderId): bool
    {
        $order = Order::whereKey($orderId)->first();
        if (!$order) {
            return false;
        }
        if ($order->status !== Order::ORDER_STATUS_COMPLETED) {
            return false;
        }
        if ($order->payment_status !== Order::PAYMENT_STATUS_SUCCESS) {
            return false;
        }

        $open = Fulfillment::where('order_id', $order->id)
            ->where('status', '!=', 'delivered')
            ->exists();
        if ($open) {
            return false;
        }

        // No fulfillments at all (e.g. digital-only): completion is owned by
        // the payment path, not shipment — do not auto-deliver.
        $any = Fulfillment::where('order_id', $order->id)->exists();
        if (!$any) {
            return false;
        }

        $this->orderService->changeOrderStatus(null, 'delivered', $order->id);

        Log::info('Order auto-delivered after all fulfillments delivered', ['order_id' => $order->id]);

        return true;
    }

    public function findByTrackingNumber(string $trackingNumber): ?Shipment
    {
        return Shipment::with(['order'])->where('tracking_number', $trackingNumber)->first();
    }

    /**
     * Canonical shipment transition authority. Locks the order row FIRST
     * (Order → Shipment — same direction as the order-cancel cascade) so the
     * same-transaction mirror sync can never introduce a reverse lock edge.
     * Re-reads under lock; refuses illegal transitions loudly.
     *
     * Timestamp semantics (F8-9): shipped_at = carrier handoff, set ONLY on
     * picked_up. The old dead 'shipped' arm is gone — no such status exists.
     */
    public function updateStatus(int $id, string $newStatus, ?string $notes = null): Shipment
    {
        return DB::transaction(function () use ($id, $newStatus, $notes) {
            $probe = Shipment::findOrFail($id);

            if ((int) $probe->order_id) {
                Order::whereKey((int) $probe->order_id)->lockForUpdate()->firstOrFail();
            }

            $shipment = Shipment::lockForUpdate()->findOrFail($id);

            if (!$shipment->canTransitionTo($newStatus)) {
                throw new \RuntimeException(
                    "Shipment {$shipment->id} cannot transition from '{$shipment->status}' to '{$newStatus}'"
                );
            }

            $this->writeStatus($shipment, $newStatus, $notes);

            $fresh = $shipment->fresh();
            $this->syncOrderMirror($fresh);

            return $fresh;
        });
    }

    /**
     * Phase 8 (D8-8): the generic update can no longer move status or forge
     * transition artifacts. Status, cancellation audit fields and transition
     * timestamps change ONLY through the transition authority
     * (updateStatus / dispatch / markDelivered / cancelShipment).
     * Everything else (courier, tracking, notes, addresses, cost…) stays
     * writable and re-syncs the order mirror in the same transaction.
     *
     * @throws \InvalidArgumentException when a protected key is present.
     */
    public function update(int $id, array $data): Shipment
    {
        $protected = [
            'status', 'cancelled_by', 'cancel_source', 'cancelled_at', 'cancel_reason',
            'shipped_at', 'delivered_at', 'fulfillment_id', 'order_id', 'uuid', 'idempotency_key',
        ];
        foreach (array_keys($data) as $key) {
            if (in_array(strtolower((string) $key), $protected, true)) {
                throw new \InvalidArgumentException(
                    "Shipment field '{$key}' is transition-authority owned and cannot be mass-updated"
                );
            }
        }

        return DB::transaction(function () use ($id, $data) {
            $probe = Shipment::findOrFail($id);

            if ((int) $probe->order_id) {
                Order::whereKey((int) $probe->order_id)->lockForUpdate()->firstOrFail();
            }

            $shipment = Shipment::lockForUpdate()->findOrFail($id);
            $shipment->update($data);

            $fresh = $shipment->fresh();
            $this->syncOrderMirror($fresh);

            return $fresh;
        });
    }

    /**
     * Phase 8 (D8-9): shipment rows are the source of truth; the legacy
     * orders.shipment_* columns are a read-model mirror. Overwrites mirror
     * status on every shipment transition; carries tracking/courier only
     * when the shipment row actually holds them (never wipes operator data
     * with NULL); stamps actual_delivery_at on delivered. Same-transaction,
     * plain keyed update — the caller already holds the order lock, so no
     * new lock edge is introduced. The mirror NEVER drives fulfillment,
     * shipment, flow, or completion state.
     */
    public function syncOrderMirror(Shipment $shipment): void
    {
        if (!Schema::hasTable('orders') || !Schema::hasColumn('orders', 'shipment_status')) {
            return;
        }

        $payload = ['shipment_status' => $shipment->status];

        if (Schema::hasColumn('orders', 'tracking_number') && $shipment->tracking_number !== null) {
            $payload['tracking_number'] = $shipment->tracking_number;
        }
        if (Schema::hasColumn('orders', 'courier_name') && $shipment->courier !== null) {
            $payload['courier_name'] = $shipment->courier;
        }
        if ($shipment->status === 'delivered' && Schema::hasColumn('orders', 'actual_delivery_at')) {
            $payload['actual_delivery_at'] = now();
        }

        Order::withoutGlobalScopes()->whereKey((int) $shipment->order_id)->update($payload);
    }

    /**
     * D8-1 application invariant (primary; the UNIQUE backstop catches the
     * lost race): no second active row for one fulfillment. Terminal rows
     * (cancelled / delivered / returned) are history and do not block.
     */
    private function assertNoActiveShipment(int $fulfillmentId): void
    {
        $active = Shipment::where('fulfillment_id', $fulfillmentId)
            ->whereNotIn('status', Shipment::TERMINAL_STATUSES)
            ->lockForUpdate()
            ->exists();

        if ($active) {
            throw new \RuntimeException(
                "Cannot create shipment: fulfillment #{$fulfillmentId} already has an active shipment"
            );
        }
    }

    /**
     * D8-3: a cancelled fulfillment can never take a new label.
     */
    private function assertFulfillmentCreatable(Fulfillment $fulfillment): void
    {
        if ($fulfillment->status === 'cancelled') {
            throw new \RuntimeException(
                "Cannot create shipment: fulfillment #{$fulfillment->id} is cancelled"
            );
        }
    }

    /**
     * D8-4 / Phase-6 boundary: re-verify the packed==picked invariant
     * read-only before dispatch when packing work exists (P6-3B parity:
     * every item's picked quantity must be covered by non-voided packages).
     * Fulfillments with no picking/packing activity pass vacuously — there
     * is nothing to verify, and no new sealing rule is invented here.
     */
    private function assertDispatchablePackages(Fulfillment $fulfillment): void
    {
        $hasWork = PackingTask::where('fulfillment_id', $fulfillment->id)->lockForUpdate()->exists()
            || Package::where('fulfillment_id', $fulfillment->id)->lockForUpdate()->exists();

        if (!$hasWork) {
            return;
        }

        $items = FulfillmentItem::where('fulfillment_id', $fulfillment->id)->lockForUpdate()->get();

        $packageIds = Package::where('fulfillment_id', $fulfillment->id)
            ->where('status', '!=', Package::STATUS_VOIDED)
            ->lockForUpdate()
            ->pluck('id');

        foreach ($items as $item) {
            $picked = (float) $item->quantity_picked;
            $packed = $packageIds->isEmpty()
                ? 0.0
                : (float) PackageItem::where('fulfillment_item_id', $item->id)
                    ->whereIn('package_id', $packageIds)
                    ->sum('quantity');

            if (abs($packed - $picked) > 0.000001) {
                throw new \RuntimeException(
                    "Cannot dispatch shipment: fulfillment item #{$item->id} picked {$picked}, packed {$packed}"
                );
            }
        }
    }

    /**
     * Locked-row status writer shared by the transition authority. The row
     * MUST already be locked by the caller; validates the DAG defensively so
     * a programming error can never skip it.
     */
    private function writeStatus(Shipment $shipment, string $newStatus, ?string $notes): void
    {
        if (!$shipment->canTransitionTo($newStatus)) {
            throw new \RuntimeException(
                "Shipment {$shipment->id} cannot transition from '{$shipment->status}' to '{$newStatus}'"
            );
        }

        $payload = [
            'status' => $newStatus,
            'notes' => $notes ?? $shipment->notes,
        ];

        if ($newStatus === 'picked_up') {
            $payload['shipped_at'] = $shipment->shipped_at ?? now();
        }
        if ($newStatus === 'delivered') {
            $payload['delivered_at'] = now();
        }

        $shipment->update($payload);
    }
}

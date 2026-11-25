<?php

namespace App\Services\Shipment;

use App\Enums\ShipmentStatus;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Package;
use App\Models\Fulfillment\PackageItem;
use App\Models\Fulfillment\PackingTask;
use App\Models\OrderTrackingEvent;
use App\Models\Shipment;
use App\Events\Shipment\ShipmentStatusChanged;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Order\DeliveryCompletionInvariant;
use App\Services\Order\OrderTransitionService;
use App\ValueObjects\TransitionActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;

class ShipmentService
{
    public function __construct(
        private FulfillmentTransition $fulfillmentTransitions,
        private OrderTransitionService $orderTransitions,
        private DeliveryCompletionInvariant $deliveryCompletion,
        private ProviderAssignmentService $providerAssignment,
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
                app(\App\Services\Warehouse\WarehouseWorkflowService::class)->assertShipmentAllowedFor($lockedFulfillment);
                $this->assertNoActiveShipment($fulfillmentId);
                $data['order_id'] = $data['order_id'] ?? $lockedFulfillment->order_id;
            }

            if (!empty($data['order_id'])) {
                Order::whereKey((int) $data['order_id'])->lockForUpdate()->first();
            }

            $assignment = $this->providerAssignment->resolve();
            $data['status'] = ShipmentStatus::PENDING->value;
            unset($data['idempotency_key']);
            // Assignment is config-owned: client-supplied courier, provider,
            // override and AWB values never select a gateway or lock this row.
            $shipment = Shipment::create(array_merge($data, $this->assignmentAttributes($assignment)));

            $this->syncOrderMirror($shipment->fresh());

            return $shipment->fresh();
        });
    }

    /**
     * Phase 8 (D8-1 / D8-3 / F8-10) + #147: explicit staff/dispatch creation
     * for one fulfillment. Requires fulfillment = ready_to_ship on the LOCKED
     * fresh row (stale models rechecked). Refuses cancelled fulfillments.
     *
     * Idempotency is deterministic at the persistence boundary:
     * - same key + same fulfillment replays the same row;
     * - same key + different fulfillment is refused loudly (409 at adapter);
     * - repeating creation for the same fulfillment returns the canonical
     *   active shipment (no duplicate active row), including unkeyed retries.
     *
     * External carrier/booking side effects MUST NOT run inside this DB
     * transaction. Any future post-commit booking flow must use a durable
     * outbox/job and reconcile unknown provider results before retrying.
     * If the canonical shipment has unknown external booking state, this
     * method refuses replay/retry until reconciliation marks a known outcome.
     *
     * The UNIQUE(active_fulfillment_id) backstop aborts a lost race loudly.
     *
     * @throws \RuntimeException on invalid state or reconciliation boundary.
     * @throws \App\Exceptions\WmsConflictException on key/active contention.
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
                    // Same-key/different-fulfillment contention → HTTP 409.
                    throw new \App\Exceptions\WmsConflictException(
                        __('wms.shipment.key_bound', ['shipment' => $existing->id, 'fulfillment' => $existing->fulfillment_id])
                    );
                }

                return $this->replayCanonicalShipment($existing, $idempotencyKey);
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
                    __('wms.shipment.create_cancelled', ['id' => $locked->id])
                );
            }
            if ($locked->status !== 'ready_to_ship') {
                throw new \RuntimeException(
                    __('wms.shipment.create_status', ['id' => $locked->id, 'status' => $locked->status])
                );
            }

            // Q-05: when shipping disabled in the pinned workflow version,
            // prevent new shipment creation. Existing shipments keep their
            // lifecycle (dispatch/deliver paths untouched). Fail-closed
            // before any mutation so retries leave state consistent.
            app(\App\Services\Warehouse\WarehouseWorkflowService::class)->assertShipmentAllowedFor($locked);

            $active = $this->activeShipmentForFulfillment($locked->id, true);
            if ($active) {
                // #147: repeating creation for the same fulfillment returns the
                // canonical active shipment. We never create a second active
                // row, and we never perform external carrier side effects here.
                return $this->replayCanonicalShipment($active, $idempotencyKey);
            }

            $assignment = $this->providerAssignment->resolve();
            // Guardrail: shipment creation is a local DB mutation only.
            // External carrier booking/label generation must happen after
            // commit via a durable outbox/job with a stable provider
            // idempotency/reference key and unknown-result reconciliation.
            $this->assertNoExternalCarrierCallInTransaction();

            $shipment = Shipment::create(array_merge([
                'order_id' => $locked->order_id,
                'fulfillment_id' => $locked->id,
            ], $data, $this->assignmentAttributes($assignment, now()->toDateTimeString()), [
                'status' => ShipmentStatus::LABEL_CREATED->value,
                'idempotency_key' => $idempotencyKey,
                'external_booking_state' => Shipment::EXTERNAL_BOOKING_NONE,
            ]));

            Log::info('Shipment created for fulfillment', [
                'shipment_id' => $shipment->id,
                'fulfillment_id' => $locked->id,
            ]);

            $this->syncOrderMirror($shipment->fresh());

            $created = $shipment->wasRecentlyCreated;
            $fresh = $shipment->fresh();
            // HTTP adapter distinguishes 201 create from 200 replay via this
            // flag; fresh() clears it, so preserve it across the reload.
            $fresh->wasRecentlyCreated = $created;

            return $fresh;
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
                    __('wms.shipment.dispatch_status', ['shipment' => $probe->id, 'fulfillment' => $fulfillment->id, 'status' => $fulfillment->status])
                );
            }

            if ($fulfillment !== null) {
                $this->assertDispatchablePackages($fulfillment);
            }

            $shipment = Shipment::lockForUpdate()->findOrFail($shipmentId);

            if (!$shipment->canTransitionTo('picked_up')) {
                throw new \RuntimeException(
                    __('wms.shipment.dispatch_state', ['shipment' => $shipment->id, 'status' => $shipment->status])
                );
            }

            $this->writeStatus($shipment, 'picked_up', $notes);

            if ($fulfillment !== null && $fulfillment->status === 'ready_to_ship') {
                $this->fulfillmentTransitions->transition($fulfillment, 'shipped', ['reason' => 'shipment_dispatched']);

                FulfillmentItem::where('fulfillment_id', $fulfillment->id)
                    ->where('status', '!=', 'cancelled')
                    ->update(['status' => 'shipped']);
            }

            $fresh = $shipment->fresh();
            $this->syncOrderMirror($fresh);

            app(\App\Services\Fulfillment\FulfillmentOrderSyncService::class)
                ->announce((int) $fresh->order_id, 'shipped', $fresh->fulfillment_id !== null ? (int) $fresh->fulfillment_id : null);

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
                    __('wms.shipment.deliver_state', ['shipment' => $probe->id, 'status' => $probe->status])
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
                    __('wms.shipment.deliver_fulfillment', ['shipment' => $probe->id, 'fulfillment' => $fulfillment->id, 'status' => $fulfillment->status])
                );
            }

            $shipment = Shipment::lockForUpdate()->findOrFail($shipmentId);

            if (!in_array($shipment->status, ['picked_up', 'in_transit', 'out_for_delivery'], true)) {
                throw new \RuntimeException(
                    __('wms.shipment.deliver_state', ['shipment' => $shipment->id, 'status' => $shipment->status])
                );
            }

            $chain = ['picked_up', 'in_transit', 'out_for_delivery', 'delivered'];
            $start = array_search($shipment->status, $chain, true);
            foreach (array_slice($chain, $start + 1) as $next) {
                if (!$shipment->canTransitionTo($next)) {
                    throw new \RuntimeException(
                        __('wms.shipment.transition', ['shipment' => $shipment->id, 'from' => $shipment->status, 'to' => $next])
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

            app(\App\Services\Fulfillment\FulfillmentOrderSyncService::class)
                ->announce((int) $fresh->order_id, 'delivered', $fresh->fulfillment_id !== null ? (int) $fresh->fulfillment_id : null);

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
            throw new \InvalidArgumentException(__('wms.shipment.cancel_reason'));
        }

        return DB::transaction(function () use ($shipmentId, $reason, $context) {
            $probe = Shipment::findOrFail($shipmentId);

            if ((int) $probe->order_id) {
                Order::whereKey((int) $probe->order_id)->lockForUpdate()->firstOrFail();
            }

            $shipment = Shipment::lockForUpdate()->findOrFail($shipmentId);

            if (!$shipment->canTransitionTo('cancelled')) {
                throw new \RuntimeException(
                    __('wms.shipment.cancel_state', ['shipment' => $shipment->id, 'status' => $shipment->status])
                );
            }

            if ($shipment->fulfillment_id) {
                $fulfillment = Fulfillment::whereKey((int) $shipment->fulfillment_id)->lockForUpdate()->first();
                if ($fulfillment !== null && in_array($fulfillment->status, ['shipped', 'delivered'], true)) {
                    throw new \RuntimeException(
                        __('wms.shipment.cancel_fulfillment', ['shipment' => $shipment->id, 'fulfillment' => $fulfillment->id, 'status' => $fulfillment->status])
                    );
                }
            }

            $cancelSource = (string) ($context['cancel_source'] ?? 'direct');
            $cancelledBy = $context['cancelled_by'] ?? (auth()->check() ? auth()->id() : null);

            $this->writeStatus($shipment, 'cancelled', $shipment->notes, [
                'cancelled_at' => now(),
                'cancel_reason' => trim($reason),
                'cancelled_by' => $cancelledBy,
                'cancel_source' => $cancelSource,
            ], [
                'source' => $cancelSource,
                'actor_id' => $cancelledBy,
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
     * re-locks inside OrderTransitionService::markDelivered. A future caller
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

        // ADR 0004: the payment/fulfillment/delivered rule lives in exactly
        // one place. `false` keeps its "stay as-is" shape here; the order
        // transition executor maps the same predicate to its own throw.
        // No fulfillments at all (e.g. digital-only): completion is owned by
        // the payment path, not shipment — do not auto-deliver.
        if (!$this->deliveryCompletion->mayComplete($order)) {
            return false;
        }

        $this->orderTransitions->markDelivered($order, TransitionActor::system());

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
    public function updateStatus(int $id, string $newStatus, ?string $notes = null, array $context = []): Shipment
    {
        return DB::transaction(function () use ($id, $newStatus, $notes, $context) {
            $probe = Shipment::findOrFail($id);

            if ((int) $probe->order_id) {
                Order::whereKey((int) $probe->order_id)->lockForUpdate()->firstOrFail();
            }

            $shipment = Shipment::lockForUpdate()->findOrFail($id);

            if (!$shipment->canTransitionTo($newStatus)) {
                throw new \RuntimeException(
                    __('wms.shipment.transition', ['shipment' => $shipment->id, 'from' => $shipment->status, 'to' => $newStatus])
                );
            }

            $this->writeStatus($shipment, $newStatus, $notes, [], $context);

            $fresh = $shipment->fresh();
            $this->syncOrderMirror($fresh);

            app(\App\Services\Fulfillment\FulfillmentOrderSyncService::class)
                ->announce((int) $fresh->order_id, 'shipment_' . $newStatus, $fresh->fulfillment_id !== null ? (int) $fresh->fulfillment_id : null);

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
                    __('wms.shipment.field_locked', ['field' => \Illuminate\Support\Str::limit((string) $key, 200)])
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

        $this->applyOrderMirror((int) $shipment->order_id, $payload);
    }

    /**
     * #137: legacy admin endpoint adapter. It has no canonical shipment row
     * (order-only labels remain valid), so this path writes the canonical
     * timeline row and the order mirror through the same authority, in the
     * same transaction, without inventing a shipment lifecycle step.
     *
     * @param  array{tracking_number?:string|null,courier_name?:string|null,location_data?:array|null,estimated_delivery_at?:mixed,notes?:string|null}  $details
     */
    public function recordOrderStatusChange(int $orderId, string $newStatus, array $details = []): void
    {
        DB::transaction(function () use ($orderId, $newStatus, $details) {
            $order = Order::withoutGlobalScopes()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $oldStatus = (string) ($order->shipment_status ?? 'pending');
            $shipment = Shipment::where('order_id', $orderId)->latest('id')->first();

            $context = $this->transitionContext(array_merge($details, ['source' => 'admin']));
            $timeline = $this->writeStatusTimeline($shipment, $oldStatus, $newStatus, $context, [
                'order_id' => (int) $order->id,
            ] + $details);

            $payload = ['shipment_status' => $newStatus];
            if (($details['tracking_number'] ?? null) !== null) {
                $payload['tracking_number'] = (string) $details['tracking_number'];
            }
            if (($details['courier_name'] ?? null) !== null) {
                $payload['courier_name'] = (string) $details['courier_name'];
            }
            if (($details['estimated_delivery_at'] ?? null) !== null) {
                $payload['estimated_delivery_at'] = $details['estimated_delivery_at'];
            }
            if ($newStatus === 'delivered') {
                $payload['actual_delivery_at'] = now();
            }
            $this->applyOrderMirror((int) $order->id, $payload);

            event(ShipmentStatusChanged::forTransition($order, $timeline, $shipment, $details + $context));
        });
    }

    /** Single persistence point for the legacy orders shipment mirror. */
    private function applyOrderMirror(int $orderId, array $payload): void
    {
        if (!Schema::hasTable('orders') || !Schema::hasColumn('orders', 'shipment_status')) {
            return;
        }

        Order::withoutGlobalScopes()->whereKey($orderId)->update($payload);
    }

    /**
     * #148: server-owned assignment attributes. A label created through the
     * fulfillment path already establishes AWB evidence; generic creation
     * does not. Client input can never forge either value.
     *
     * @param  array{provider_code:string,service_level:string,mapping_version:string,provider_mode:string}  $assignment
     * @return array<string,mixed>
     */
    private function assignmentAttributes(array $assignment, ?string $awbCreatedAt = null): array
    {
        return [
            'provider_code' => $assignment['provider_code'],
            'provider_mode' => $assignment['provider_mode'],
            'mapping_version' => $assignment['mapping_version'],
            'provider_service_level' => $assignment['service_level'],
            'provider_override' => false,
            'awb_created_at' => $awbCreatedAt,
        ];
    }

    /**
     * #147: canonical active shipment lookup. When $lock is true, caller must
     * be inside a DB transaction and hold the fulfillment/order lock order.
     */
    private function activeShipmentForFulfillment(int $fulfillmentId, bool $lock = false): ?Shipment
    {
        $query = Shipment::where('fulfillment_id', $fulfillmentId)
            ->whereNotIn('status', Shipment::TERMINAL_STATUSES)
            ->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * #147: replay path for same-fulfillment creation retries. The canonical
     * shipment is returned unchanged; no external carrier call occurs here.
     * Unknown external booking state is a hard reconciliation boundary: retry
     * is refused before any side effect can be attempted.
     */
    private function replayCanonicalShipment(Shipment $shipment, ?string $idempotencyKey = null): Shipment
    {
        $shipment = $shipment->fresh() ?? $shipment;
        $this->assertExternalBookingReconciled($shipment);

        if ($idempotencyKey !== null && $shipment->idempotency_key === null) {
            // Attach caller key to canonical row for deterministic future
            // replays. This is a local metadata update, not an external call.
            $shipment->forceFill(['idempotency_key' => $idempotencyKey])->save();
        }

        return $shipment->fresh() ?? $shipment;
    }

    private function assertExternalBookingReconciled(Shipment $shipment): void
    {
        if (!$shipment->requiresExternalBookingReconciliation()) {
            return;
        }

        // #147 AC4: unknown external result must be reconciled before retry.
        // This is an explicit boundary; actual provider lookup is a post-commit
        // concern and must not run in a DB transaction.
        throw new \App\Exceptions\WmsConflictException(
            __('wms.shipment.external_booking_reconciliation_required', ['shipment' => $shipment->id])
        );
    }

    /**
     * #147 AC3 guardrail: shipment creation currently performs no external
     * carrier call. Keep this explicit so future booking integration cannot be
     * silently added inside a transaction.
     */
    private function assertNoExternalCarrierCallInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \RuntimeException('Shipment creation external-call guard requires an active DB transaction.');
        }

        // No-op by design: no HTTP/carrier SDK/queue side effects in tx.
        // Post-commit dispatch belongs in durable outbox/job flows.
    }

    /**
     * #147 AC4: reconcile an unknown/ambiguous external booking result before
     * allowing retry. This records a known outcome from a provider lookup or
     * operator reconciliation; it does not call external carriers.
     *
     * @param  array{outcome:string,reference?:string|null,note?:string|null}  $reconciliation
     */
    public function reconcileExternalBookingResult(int $shipmentId, array $reconciliation): Shipment
    {
        $outcome = strtolower(trim((string) ($reconciliation['outcome'] ?? '')));
        $allowed = [
            Shipment::EXTERNAL_BOOKING_CONFIRMED,
            Shipment::EXTERNAL_BOOKING_RECONCILED,
            Shipment::EXTERNAL_BOOKING_FAILED,
        ];
        if (!in_array($outcome, $allowed, true)) {
            throw new \InvalidArgumentException(
                __('wms.shipment.external_booking_reconciliation_outcome_invalid')
            );
        }

        return DB::transaction(function () use ($shipmentId, $outcome, $reconciliation) {
            $shipment = Shipment::lockForUpdate()->findOrFail($shipmentId);
            if (!$shipment->hasUnknownExternalBooking()) {
                throw new \App\Exceptions\WmsConflictException(
                    __('wms.shipment.external_booking_not_unknown', ['shipment' => $shipment->id])
                );
            }

            $shipment->forceFill([
                'external_booking_state' => $outcome,
                'external_booking_reference' => array_key_exists('reference', $reconciliation)
                    ? ($reconciliation['reference'] !== null ? trim((string) $reconciliation['reference']) : null)
                    : $shipment->external_booking_reference,
                'external_booking_reconciled_at' => now(),
                'external_booking_reconciliation_note' => array_key_exists('note', $reconciliation)
                    ? ($reconciliation['note'] !== null ? trim((string) $reconciliation['note']) : null)
                    : $shipment->external_booking_reconciliation_note,
            ])->save();

            Log::warning('Shipment external booking reconciled', [
                'shipment_id' => $shipment->id,
                'fulfillment_id' => $shipment->fulfillment_id,
                'outcome' => $outcome,
                'reference' => $shipment->external_booking_reference,
            ]);

            return $shipment->fresh();
        });
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
            // One-active-shipment contention → HTTP 409 at the adapter.
            throw new \App\Exceptions\WmsConflictException(
                __('wms.shipment.active_exists', ['id' => $fulfillmentId])
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
                __('wms.shipment.create_cancelled', ['id' => $fulfillment->id])
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
                    __('wms.shipment.dispatch_quantities', ['item' => $item->id, 'picked' => $picked, 'packed' => $packed])
                );
            }
        }
    }

    /**
     * Locked-row status writer shared by the transition authority. The row
     * MUST already be locked by the caller; validates the DAG defensively so
     * a programming error can never skip it.
     *
     * #137: status, canonical timeline row and order mirror belong to ONE
     * transaction (the caller's transaction). A timeline failure propagates
     * and rolls back the status write; the event is deferred until commit by
     * ShipmentStatusChanged's ShouldDispatchAfterCommit contract.
     */
    private function writeStatus(
        Shipment $shipment,
        string $newStatus,
        ?string $notes,
        array $extraPayload = [],
        array $transitionContext = [],
    ): OrderTrackingEvent {
        if (!$shipment->canTransitionTo($newStatus)) {
            throw new \RuntimeException(
                __('wms.shipment.transition', ['shipment' => $shipment->id, 'from' => $shipment->status, 'to' => $newStatus])
            );
        }

        $oldStatus = (string) $shipment->status;
        $payload = array_merge([
            'status' => $newStatus,
            'notes' => $notes ?? $shipment->notes,
        ], $extraPayload);

        if ($newStatus === 'picked_up') {
            $payload['shipped_at'] = $shipment->shipped_at ?? now();
        }
        if ($newStatus === 'delivered') {
            $payload['delivered_at'] = now();
        }

        $shipment->update($payload);

        $context = $this->transitionContext($transitionContext);
        $timeline = $this->writeStatusTimeline($shipment, $oldStatus, $newStatus, $context);
        $order = Order::withoutGlobalScopes()->whereKey((int) $shipment->order_id)->firstOrFail();

        event(ShipmentStatusChanged::forTransition($order, $timeline, $shipment, array_merge($context, [
            'tracking_number' => $shipment->tracking_number,
            'courier_name' => $shipment->courier,
            'estimated_delivery_at' => $shipment->estimated_delivery_at,
        ])));

        return $timeline;
    }

    /**
     * #137: the canonical shipment timeline writer. No try/catch by design —
     * a failure here must abort the enclosing transition transaction.
     *
     * @param  array{actor_type:string,actor_id:?int,actor_name:string,source:string}  $context
     */
    private function writeStatusTimeline(
        ?Shipment $shipment,
        string $oldStatus,
        string $newStatus,
        array $context,
        array $details = [],
    ): OrderTrackingEvent {
        $orderId = (int) ($details['order_id'] ?? $shipment?->order_id ?? 0);
        $estimated = $details['estimated_delivery_at'] ?? $shipment?->estimated_delivery_at;
        $metadata = [
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'tracking_number' => $details['tracking_number'] ?? $shipment?->tracking_number,
            'courier_name' => $details['courier_name'] ?? $shipment?->courier,
            'location_data' => $details['location_data'] ?? null,
            'estimated_delivery' => $estimated instanceof \DateTimeInterface
                ? (method_exists($estimated, 'toIso8601String') ? $estimated->toIso8601String() : $estimated->format(\DateTimeInterface::ATOM))
                : null,
            'changed_at' => now()->toIso8601String(),
        ];

        return OrderTrackingEvent::create([
            'order_id' => $orderId,
            'shipment_id' => $shipment?->id ? (int) $shipment->id : null,
            'event_type' => 'shipment.status_changed',
            'event_timestamp' => now(),
            'actor_type' => $context['actor_type'],
            'actor_id' => $context['actor_id'],
            'actor_name' => $context['actor_name'],
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'metadata' => $metadata,
            'customer_visible' => $this->timelineCustomerVisible($newStatus),
            'customer_label_key' => "tracking.shipment.{$newStatus}",
            'customer_description_key' => "tracking.shipment.{$newStatus}_description",
            'admin_notes' => null,
            'source' => $context['source'],
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * Single actor/source derivation for every canonical transition. Explicit
     * context wins; ambient auth is consulted once at the authority boundary,
     * never in a controller, listener or resource.
     *
     * @param  array{actor_type?:string,actor_id?:int|null,actor_name?:string,source?:string}  $context
     * @return array{actor_type:string,actor_id:?int,actor_name:string,source:string}
     */
    private function transitionContext(array $context = []): array
    {
        $user = auth()->user();

        return [
            'actor_type' => $context['actor_type'] ?? ($user ? TransitionActor::user($user)->historyActorType() : TransitionActor::KIND_SYSTEM),
            'actor_id' => array_key_exists('actor_id', $context)
                ? ($context['actor_id'] !== null ? (int) $context['actor_id'] : null)
                : ($user?->getAuthIdentifier() !== null ? (int) $user->getAuthIdentifier() : null),
            'actor_name' => $context['actor_name'] ?? ($user?->name ?? 'System'),
            'source' => $context['source'] ?? 'direct',
        ];
    }

    private function timelineCustomerVisible(string $status): bool
    {
        return !in_array($status, ['pending', 'label_created'], true);
    }

    /**
     * Bulk create shipments for multiple fulfillments.
     * Fault-tolerant: successful items progress, failed items report explicit reasons.
     *
     * @param int[] $fulfillmentIds
     * @param array $commonData
     * @return array{successful: array, failed: array, summary: array}
     */
    public function bulkCreateForFulfillments(array $fulfillmentIds, array $commonData = []): array
    {
        $uniqueIds = array_values(array_unique(array_map('intval', $fulfillmentIds)));
        $successful = [];
        $failed = [];

        foreach ($uniqueIds as $fulfillmentId) {
            $fulfillment = Fulfillment::find($fulfillmentId);
            if (!$fulfillment) {
                $failed[] = [
                    'fulfillment_id' => $fulfillmentId,
                    'reason' => "Fulfillment #{$fulfillmentId} not found",
                ];
                continue;
            }

            try {
                $shipment = $this->createForFulfillment($fulfillment, $commonData);
                $successful[] = [
                    'fulfillment_id' => $fulfillment->id,
                    'fulfillment_number' => $fulfillment->fulfillment_number,
                    'shipment_id' => $shipment->id,
                    'tracking_number' => $shipment->tracking_number,
                    'status' => $shipment->status,
                    'courier' => $shipment->courier,
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'fulfillment_id' => $fulfillmentId,
                    'fulfillment_number' => $fulfillment->fulfillment_number,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return [
            'successful' => $successful,
            'failed' => $failed,
            'summary' => [
                'total' => count($uniqueIds),
                'success_count' => count($successful),
                'failed_count' => count($failed),
            ],
        ];
    }

    /**
     * Bulk dispatch multiple shipments to carrier (Carrier Manifest Handoff).
     *
     * @param int[] $shipmentIds
     * @return array{successful: array, failed: array, summary: array}
     */
    public function bulkDispatch(array $shipmentIds, ?string $notes = null): array
    {
        $uniqueIds = array_values(array_unique(array_map('intval', $shipmentIds)));
        $successful = [];
        $failed = [];

        foreach ($uniqueIds as $shipmentId) {
            $shipment = Shipment::find($shipmentId);
            if (!$shipment) {
                $failed[] = [
                    'shipment_id' => $shipmentId,
                    'reason' => "Shipment #{$shipmentId} not found",
                ];
                continue;
            }

            try {
                $dispatched = $this->dispatch($shipmentId, $notes);
                $successful[] = [
                    'shipment_id' => $dispatched->id,
                    'tracking_number' => $dispatched->tracking_number,
                    'status' => $dispatched->status,
                    'fulfillment_id' => $dispatched->fulfillment_id,
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'shipment_id' => $shipmentId,
                    'tracking_number' => $shipment->tracking_number,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return [
            'successful' => $successful,
            'failed' => $failed,
            'summary' => [
                'total' => count($uniqueIds),
                'success_count' => count($successful),
                'failed_count' => count($failed),
            ],
        ];
    }

    /**
     * Bulk deliver multiple shipments.
     *
     * @param int[] $shipmentIds
     * @return array{successful: array, failed: array, summary: array}
     */
    public function bulkDeliver(array $shipmentIds, ?string $notes = null): array
    {
        $uniqueIds = array_values(array_unique(array_map('intval', $shipmentIds)));
        $successful = [];
        $failed = [];

        foreach ($uniqueIds as $shipmentId) {
            $shipment = Shipment::find($shipmentId);
            if (!$shipment) {
                $failed[] = [
                    'shipment_id' => $shipmentId,
                    'reason' => "Shipment #{$shipmentId} not found",
                ];
                continue;
            }

            try {
                $delivered = $this->markDelivered($shipmentId, $notes);
                $successful[] = [
                    'shipment_id' => $delivered->id,
                    'tracking_number' => $delivered->tracking_number,
                    'status' => $delivered->status,
                    'fulfillment_id' => $delivered->fulfillment_id,
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'shipment_id' => $shipmentId,
                    'tracking_number' => $shipment->tracking_number,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return [
            'successful' => $successful,
            'failed' => $failed,
            'summary' => [
                'total' => count($uniqueIds),
                'success_count' => count($successful),
                'failed_count' => count($failed),
            ],
        ];
    }
}

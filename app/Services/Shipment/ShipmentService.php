<?php

namespace App\Services\Shipment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Shipment;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\General\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function create(array $data): Shipment
    {
        return DB::transaction(function () use ($data) {
            $data['status'] = 'pending';
            return Shipment::create($data);
        });
    }

    /**
     * Phase 11: create a shipment for a fulfillment at the approved boundary.
     * Requires fulfillment = ready_to_ship (locked check). Idempotent per key.
     *
     * @throws \RuntimeException when the fulfillment is not ready to ship.
     */
    public function createForFulfillment(Fulfillment $fulfillment, array $data = [], ?string $idempotencyKey = null): Shipment
    {
        if ($idempotencyKey !== null) {
            $existing = Shipment::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($fulfillment, $data, $idempotencyKey) {
            $locked = Fulfillment::whereKey($fulfillment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'ready_to_ship') {
                throw new \RuntimeException(
                    "Cannot create shipment: fulfillment #{$locked->id} is {$locked->status}, expected ready_to_ship"
                );
            }

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

            return $shipment->fresh();
        });
    }

    /**
     * Phase 11: dispatch (carrier handoff). Advances shipment label_created →
     * picked_up and fulfillment ready_to_ship → shipped atomically.
     */
    public function dispatch(int $shipmentId, ?string $notes = null): Shipment
    {
        $shipment = $this->updateStatus($shipmentId, 'picked_up', $notes);

        if ($shipment->fulfillment_id) {
            $fulfillment = Fulfillment::find($shipment->fulfillment_id);
            if ($fulfillment && $fulfillment->status === 'ready_to_ship') {
                $this->fulfillmentTransitions->transition($fulfillment, 'shipped', ['reason' => 'shipment_dispatched']);
            }
        }

        return $shipment->fresh();
    }

    /**
     * Phase 11: delivery confirmation. Walks the carrier chain to delivered
     * (picked_up → in_transit → out_for_delivery → delivered), advances the
     * fulfillment, then evaluates the order completion rule.
     */
    public function markDelivered(int $shipmentId, ?string $notes = null): Shipment
    {
        $shipment = Shipment::findOrFail($shipmentId);
        foreach (['in_transit', 'out_for_delivery', 'delivered'] as $next) {
            $shipment->refresh();
            if ($shipment->status === 'delivered') {
                break;
            }
            if ($shipment->canTransitionTo($next)) {
                $shipment = $this->updateStatus($shipment->id, $next, $notes);
            }
        }

        if ($shipment->fulfillment_id) {
            $fulfillment = Fulfillment::find($shipment->fulfillment_id);
            if ($fulfillment && $fulfillment->status !== 'delivered') {
                $this->fulfillmentTransitions->transition($fulfillment, 'delivered', ['reason' => 'shipment_delivered']);
            }
        }

        $this->maybeCompleteOrder((int) $shipment->order_id);

        return $shipment->fresh();
    }

    /**
     * Order completion rule (locked §22): completed + payment-success + every
     * fulfillment delivered → delivered. Otherwise the order stays as-is.
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

    public function updateStatus(int $id, string $newStatus, ?string $notes = null): Shipment
    {
        return DB::transaction(function () use ($id, $newStatus, $notes) {
            $shipment = Shipment::lockForUpdate()->findOrFail($id);

            if (!$shipment->canTransitionTo($newStatus)) {
                throw new \RuntimeException(
                    "Shipment {$shipment->id} cannot transition from '{$shipment->status}' to '{$newStatus}'"
                );
            }

            $timestamps = [];
            if ($newStatus === 'shipped' || $newStatus === 'picked_up') {
                $timestamps['shipped_at'] = $shipment->shipped_at ?? now();
            }
            if ($newStatus === 'delivered') {
                $timestamps['delivered_at'] = now();
            }

            $shipment->update(array_merge([
                'status' => $newStatus,
                'notes' => $notes ?? $shipment->notes,
            ], $timestamps));

            return $shipment->fresh();
        });
    }

    public function update(int $id, array $data): Shipment
    {
        $shipment = Shipment::findOrFail($id);
        $shipment->update($data);
        return $shipment->fresh();
    }
}

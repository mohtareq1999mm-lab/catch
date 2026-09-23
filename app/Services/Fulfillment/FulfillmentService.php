<?php

namespace App\Services\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Warehouse;
use Marvel\Database\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FulfillmentService
{
    public function __construct(
        private ProductLocationService $productLocationService,
        private FulfillmentTransition $transitions,
    ) {}

    /**
     * Phase 6: release an order to the warehouse (Release-to-Warehouse rule).
     *
     * Releasable states (locked):
     * - capture methods (online + future gateways): inventory COMMITTED +
     *   payment success (commit and capture coincide in the callback).
     * - deferred methods (cod, pay_at_cashier): inventory ACTIVE + payment
     *   pending (capture happens at delivery / mark-paid).
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
        if ($idempotencyKey !== null) {
            $existing = Fulfillment::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
        }

        $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
        $this->assertReleasable($fresh);

        if ($warehouseId === null) {
            $warehouseId = Warehouse::default()->firstOrFail()->id;
        }

        if ($idempotencyKey === null) {
            $pending = Fulfillment::where('order_id', $fresh->id)
                ->where('warehouse_id', $warehouseId)
                ->where('status', 'pending')
                ->first();
            if ($pending) {
                return $pending;
            }
        }

        $fulfillment = DB::transaction(function () use ($fresh, $warehouseId, $idempotencyKey) {
            $created = $this->createFromOrder($fresh, $warehouseId);
            if ($idempotencyKey !== null) {
                $created->update(['idempotency_key' => $idempotencyKey]);
            }

            return $created->fresh();
        });

        Log::info('Fulfillment released to warehouse', [
            'fulfillment_id' => $fulfillment->id,
            'order_id' => $fresh->id,
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
            // Capture happens later (delivery / mark-paid): an ACTIVE
            // reservation is the releasable state.
            if ($order->inventory_state !== Order::INVENTORY_STATE_ACTIVE) {
                throw new \RuntimeException(
                    "COD order #{$order->id} has no active reservation (state: {$order->inventory_state})"
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
            // Get default warehouse if not specified
            if (!$warehouseId) {
                $warehouse = Warehouse::default()->firstOrFail();
                $warehouseId = $warehouse->id;
            }

            // Generate fulfillment number
            $fulfillmentNumber = $this->generateFulfillmentNumber();

            // Create fulfillment record
            $fulfillment = Fulfillment::create([
                'order_id' => $order->id,
                'warehouse_id' => $warehouseId,
                'fulfillment_number' => $fulfillmentNumber,
                'status' => 'pending',
                'priority' => $this->determinePriority($order),
            ]);

            // Create fulfillment items with location allocation
            foreach ($order->orderItems as $orderItem) {
                $this->createFulfillmentItem(
                    $fulfillment,
                    $orderItem,
                    $warehouseId
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

            // Create fulfillment item for each allocation
            foreach ($allocations as $allocation) {
                FulfillmentItem::create([
                    'fulfillment_id' => $fulfillment->id,
                    'order_item_id' => $orderItem->id,
                    'product_id' => $productId,
                    'product_variant_id' => $orderItem->variation_option_id,
                    'product_location_id' => $allocation['location_id'],
                    'quantity' => $allocation['allocated'],
                    'quantity_picked' => 0,
                    'status' => 'pending',
                ]);
            }
        } catch (\Exception $e) {
            // If allocation fails, create without location (manual assignment needed)
            Log::warning('Failed to allocate location for fulfillment item', [
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
     * Assign fulfillment to user
     */
    public function assignToUser(Fulfillment $fulfillment, int $userId): Fulfillment
    {
        $fulfillment->update(['assigned_to' => $userId]);

        Log::info('Fulfillment assigned to user', [
            'fulfillment_id' => $fulfillment->id,
            'user_id' => $userId,
        ]);

        return $fulfillment->fresh();
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

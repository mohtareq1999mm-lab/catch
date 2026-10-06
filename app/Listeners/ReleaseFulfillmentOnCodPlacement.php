<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Inventory\OrderReservationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Order;
use Throwable;

/**
 * Phase 3 (D-TRIGGER-AUTO, manual payments): successful manual-payment
 * placement (COD + pay-at-cashier) → automatic physical fulfillment release.
 *
 * OrderCreated is dispatched by finalizeOrder() strictly after the
 * order/reservation transaction commits, so the secured inventory the
 * release gate requires is already established (verified defensively below).
 * Strictly manual methods (`payment_method` in cod / pay_at_cashier):
 * online orders are handled by the verified-payment path. Cashier orders
 * release here at placement (not at mark-paid) so in-store pickup
 * preparation starts immediately; a later mark-paid converges on the same
 * idempotent automatic key instead of a second fulfillment.
 *
 * (Historical class name retained to limit blast radius; it now covers all
 * manual-payment placements, not COD alone.)
 *
 * Same deterministic key, warehouse (default), retry and reporting
 * semantics as the payment listener — one fulfillment per order.
 */
class ReleaseFulfillmentOnCodPlacement implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    public $afterCommit = true;

    /**
     * Bounded automatic retry — same contract as the payment listener:
     * temporary failures redeliver with backoff (tries exhaust into
     * failed(), which logs for ops); permanent business states skip
     * inside handle(). The release sweeper (fulfillment:release-ready)
     * recovers anything the queue cannot.
     */
    public $tries = 5;

    public $backoff = [10, 30, 60, 120, 300];

    public function __construct(
        private FulfillmentService $fulfillments,
        private OrderReservationService $reservations,
    ) {}

    public function handle(OrderCreated $event): void
    {
        $order = $event->order instanceof Order ? $event->order->fresh() : null;
        if (!$order) {
            Log::warning('COD auto-release skipped: order missing in OrderCreated', [
                'order_id' => $event->order?->id,
            ]);

            return;
        }

        if (!in_array($order->payment_method ?? null, ['cod', 'pay_at_cashier'], true)) {
            return; // not our trigger (online → verified-payment path)
        }

        if (!$this->reservations->hasPhysicalLines($order)) {
            Log::info('Manual-payment auto-release skipped: no physical lines (digital-only)', [
                'order_id' => $order->id,
            ]);

            return;
        }

        // Phase 3 addendum: COD/cashier commit at creation, so COMMITTED
        // is the normal secured state; ACTIVE is still accepted for legacy
        // orders placed before the commit-at-creation rule.
        if (!in_array($order->inventory_state ?? null, [Order::INVENTORY_STATE_ACTIVE, Order::INVENTORY_STATE_COMMITTED], true)) {
            Log::warning('Manual-payment auto-release skipped: inventory not secured', [
                'order_id' => $order->id,
                'inventory_state' => $order->inventory_state,
            ]);

            return;
        }

        try {
            $fulfillment = $this->fulfillments->releaseForOrder(
                $order,
                null,
                FulfillmentService::automaticReleaseKey((int) $order->id)
            );
        } catch (\RuntimeException $e) {
            $state = (string) $order->refresh()->status;
            if (in_array($state, ['cancelled', 'delivered'], true)) {
                Log::info('COD auto-release skipped: order is terminal', [
                    'order_id' => $order->id,
                    'status' => $state,
                ]);

                return;
            }

            throw $e;
        }

        Log::info('Fulfillment auto-released on manual-payment placement', [
            'order_id' => $order->id,
            'fulfillment_id' => $fulfillment->id,
            'warehouse_id' => $fulfillment->warehouse_id,
        ]);
    }

    public function failed(OrderCreated $event, Throwable $exception): void
    {
        Log::error('Manual-payment fulfillment auto-release permanently failed after retries.', [
            'order_id' => $event->order?->id,
            'error' => $exception->getMessage(),
        ]);
    }
}

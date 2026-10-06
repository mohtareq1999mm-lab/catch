<?php

namespace App\Listeners;

use App\Events\PaymentSucceeded;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Inventory\OrderReservationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Order;
use Throwable;

/**
 * Phase 3 (D-TRIGGER-AUTO, online): verified payment success → automatic
 * physical fulfillment release.
 *
 * Runs on the existing high queue, after commit (event is
 * ShouldDispatchAfterCommit). Digital-only orders are skipped (entitlements
 * path owns them). The deterministic automatic key makes replays reuse the
 * one fulfillment row. Permanent terminal states (cancelled/delivered) skip
 * without retry; anything else rethrows for queue retry and failed()
 * reporting. Never touches payment, inventory, or order lifecycle state.
 */
class ReleaseFulfillmentOnPayment implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    public $afterCommit = true;

    /**
     * Bounded automatic retry for temporary technical failures (locked
     * rows, warehouse outage, deadlock). Permanent business refusals
     * (cancelled/delivered/digital-only/missing warehouse shape) skip
     * without retry inside handle(); anything else rethrows here and the
     * queue redelivers with backoff. Exhaustion lands in failed() (logged
     * for ops) and the release sweeper (fulfillment:release-ready) remains
     * the automatic recovery path — no order is ever silently abandoned.
     */
    public $tries = 5;

    public $backoff = [10, 30, 60, 120, 300];

    public function __construct(
        private FulfillmentService $fulfillments,
        private OrderReservationService $reservations,
    ) {}

    public function handle(PaymentSucceeded $event): void
    {
        $order = $event->order instanceof Order ? $event->order->fresh() : null;
        if (!$order) {
            Log::warning('Auto-release skipped: order missing in PaymentSucceeded', [
                'order_id' => $event->order?->id,
            ]);

            return;
        }

        if (!$this->reservations->hasPhysicalLines($order)) {
            Log::info('Auto-release skipped: no physical lines (digital-only)', [
                'order_id' => $order->id,
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
            // Terminal order state: retry can never heal — skip loudly.
            $state = (string) $order->refresh()->status;
            if (in_array($state, ['cancelled', 'delivered'], true)) {
                Log::info('Auto-release skipped: order is terminal', [
                    'order_id' => $order->id,
                    'status' => $state,
                ]);

                return;
            }

            throw $e;
        }

        Log::info('Fulfillment auto-released on verified payment', [
            'order_id' => $order->id,
            'fulfillment_id' => $fulfillment->id,
            'warehouse_id' => $fulfillment->warehouse_id,
        ]);
    }

    public function failed(PaymentSucceeded $event, Throwable $exception): void
    {
        Log::error('Fulfillment auto-release permanently failed after retries.', [
            'order_id' => $event->order?->id,
            'error' => $exception->getMessage(),
        ]);
    }
}

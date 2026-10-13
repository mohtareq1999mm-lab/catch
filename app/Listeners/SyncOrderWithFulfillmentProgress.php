<?php

namespace App\Listeners;

use App\Events\Fulfillment\FulfillmentProgressed;
use App\Events\PaymentSucceeded;
use App\Services\Fulfillment\FulfillmentOrderSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Converge the order after fulfillment progress or payment completion.
 *
 * Runs on the existing high queue, after commit (both events implement
 * ShouldDispatchAfterCommit). The sync service targets logistics steps only
 * (never completed/cancelled) through OrderService::changeOrderStatus, so:
 * - payment rules are untouched (completed stays payment-owned),
 * - the order-cancel cascade can never fire (cancelled never targeted),
 * - repeats/duplicates converge via same-state no-ops + row locks.
 *
 * Illegal transitions (paid-order intermediates L-01, customs steps L-02)
 * are logged skips inside the service — they do NOT rethrow. Unexpected
 * failures rethrow for bounded queue retry; exhaustion lands in failed().
 */
class SyncOrderWithFulfillmentProgress implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    public $afterCommit = true;

    public $tries = 5;

    public $backoff = [10, 30, 60, 120, 300];

    public function __construct(
        private FulfillmentOrderSyncService $sync,
    ) {}

    public function handle(FulfillmentProgressed | PaymentSucceeded $event): void
    {
        $orderId = $event instanceof FulfillmentProgressed
            ? $event->orderId
            : (int) $event->order?->getKey();

        if ($orderId <= 0) {
            Log::warning('Fulfillment-order sync skipped: order missing in event', [
                'event' => $event::class,
            ]);

            return;
        }

        $reason = $event instanceof FulfillmentProgressed
            ? $event->milestone
            : 'payment_completed';

        $result = $this->sync->sync($orderId, $reason);

        Log::info('Fulfillment-order sync converged', [
            'order_id' => $orderId,
            'reason' => $reason,
            'fulfillment_id' => $event instanceof FulfillmentProgressed ? $event->fulfillmentId : null,
            'advanced' => $result['advanced'],
            'skipped' => $result['skipped'],
            'delivered' => $result['delivered'],
        ]);
    }

    public function failed(FulfillmentProgressed | PaymentSucceeded $event, Throwable $exception): void
    {
        Log::error('Fulfillment-order sync permanently failed after retries.', [
            'event' => $event::class,
            'error' => $exception->getMessage(),
        ]);
    }
}

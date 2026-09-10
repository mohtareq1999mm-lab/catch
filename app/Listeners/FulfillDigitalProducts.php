<?php

namespace App\Listeners;

use App\Events\PaymentSucceeded;
use App\Notifications\AdminDigitalDeliveryFailedNotification;
use App\Services\Digital\DigitalFulfillmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class FulfillDigitalProducts implements ShouldQueue
{
    /**
     * Payment-critical latency — matches the meem-high supervisor worker
     * (tries=5, timeout=90s). Retries are safe: fulfillment is idempotent.
     */
    public $queue = 'meem-high';

    public $afterCommit = true;

    public function __construct(private DigitalFulfillmentService $fulfillmentService) {}

    public function handle(PaymentSucceeded $event): void
    {
        try {
            $this->fulfillmentService->fulfillOrder($event->order);
        } catch (Throwable $e) {
            // Re-throw so the queue retries (tries=5). Admin is notified on
            // the FINAL failure via failed().
            throw $e;
        }
    }

    public function failed(PaymentSucceeded $event, Throwable $exception): void
    {
        Log::error('Digital fulfillment permanently failed.', [
            'order_id' => $event->order->id,
            'error' => $exception->getMessage(),
        ]);

        try {
            $admins = \Marvel\Database\Models\User::role('super_admin')->get();

            foreach ($admins as $admin) {
                $admin->notify(new AdminDigitalDeliveryFailedNotification($event->order, $exception->getMessage()));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}

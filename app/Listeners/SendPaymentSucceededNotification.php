<?php

namespace App\Listeners;

use App\Events\PaymentSucceeded;
use App\Jobs\LogActivityJob;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPaymentSucceededNotification implements ShouldQueue
{
    /**
     * P2: forward-compatible declaration. Laravel 10.30 ignores this on
     * queued listeners — commit-safety is guaranteed by PaymentSucceeded
     * implementing ShouldDispatchAfterCommit. Kept so a future framework
     * upgrade honors per-listener deferral too.
     */
    public $afterCommit = true;

    public $queue = \App\Enums\QueueName::MEDIUM->value;

    public function handle(PaymentSucceeded $event): void
    {
        $order = $event->order;

        $description = __('activity.payment_succeeded') ?: 'Payment succeeded';

        LogActivityJob::dispatch(
            get_class($order),
            $order->id,
            $order->user_id,
            'payment_succeeded',
            'orders',
            $description,
            [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_price' => $order->total_price,
                'status' => $order->status,
                'payment_gateway' => $order->payment_gateway,
            ],
        );
    }
}

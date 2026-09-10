<?php

namespace App\Listeners;

use App\Events\PaymentFailed;
use App\Jobs\LogActivityJob;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPaymentFailedNotification implements ShouldQueue
{
    public $queue = 'meem-medium';

    public function handle(PaymentFailed $event): void
    {
        $order = $event->order;

        $description = __('activity.payment_failed') ?: 'Payment failed';

        LogActivityJob::dispatch(
            get_class($order),
            $order->id,
            $order->user_id,
            'payment_failed',
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

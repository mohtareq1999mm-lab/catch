<?php

namespace App\Listeners;

use App\Events\OrderCancelled;
use App\Jobs\LogActivityJob;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendOrderCancelledNotification implements ShouldQueue
{
    public $queue = 'meem-medium';

    public function handle(OrderCancelled $event): void
    {
        $order = $event->order;

        $description = __('activity.order_cancelled') ?: 'Order cancelled';

        LogActivityJob::dispatch(
            get_class($order),
            $order->id,
            $order->user_id,
            'order_cancelled',
            'orders',
            $description,
            [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_price' => $order->total_price,
                'status' => $order->status,
            ],
        );
    }
}

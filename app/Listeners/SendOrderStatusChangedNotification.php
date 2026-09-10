<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Jobs\LogActivityJob;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendOrderStatusChangedNotification implements ShouldQueue
{
    public $queue = 'meem-medium';

    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;

        $description = __('activity.order_status_changed') ?: 'Order status changed';

        LogActivityJob::dispatch(
            get_class($order),
            $order->id,
            $order->user_id,
            'order_status_changed',
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

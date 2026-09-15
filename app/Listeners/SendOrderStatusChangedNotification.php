<?php

namespace App\Listeners;

use App\Audit\ActivityAuditService;
use App\Events\OrderStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendOrderStatusChangedNotification implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;

        $description = __('activity.order_status_changed') ?: 'Order status changed';

        ActivityAuditService::recordSubject(
            get_class($order),
            (int) $order->id,
            'order_status_changed',
            'orders',
            $description,
            old: ['status' => $order->getOriginal('status') ?? $order->status],
            new: [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_price' => $order->total_price,
                'status' => $order->status,
            ],
            context: ['source' => 'queue', 'job' => self::class],
            causerId: (int) $order->user_id,
            causerType: \Marvel\Database\Models\User::class,
        );
    }
}

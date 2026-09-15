<?php

namespace App\Listeners;

use App\Audit\ActivityAuditService;
use App\Events\PaymentFailed;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPaymentFailedNotification implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    public function handle(PaymentFailed $event): void
    {
        $order = $event->order;

        $description = __('activity.payment_failed') ?: 'Payment failed';

        ActivityAuditService::recordSubject(
            get_class($order),
            (int) $order->id,
            'payment_failed',
            'orders',
            $description,
            new: [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_price' => $order->total_price,
                'status' => $order->status,
                'payment_gateway' => $order->payment_gateway,
            ],
            context: ['source' => 'queue', 'job' => self::class],
            causerId: (int) $order->user_id,
            causerType: \Marvel\Database\Models\User::class,
        );
    }
}

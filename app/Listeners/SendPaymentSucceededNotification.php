<?php

namespace App\Listeners;

use App\Audit\ActivityAuditService;
use App\Events\PaymentSucceeded;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPaymentSucceededNotification implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    /**
     * P2: forward-compatible declaration. Laravel 10.30 ignores this on
     * queued listeners — commit-safety is guaranteed by PaymentSucceeded
     * implementing ShouldDispatchAfterCommit. Kept so a future framework
     * upgrade honors per-listener deferral too.
     */
    public $afterCommit = true;
    public function handle(PaymentSucceeded $event): void
    {
        $order = $event->order;

        $description = __('activity.payment_succeeded') ?: 'Payment succeeded';

        ActivityAuditService::recordSubject(
            get_class($order),
            (int) $order->id,
            'payment_succeeded',
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

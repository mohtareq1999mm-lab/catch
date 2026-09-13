<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\PaymentSucceeded;
use App\Notifications\UserPaymentSucceededNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendUserPaymentSucceededNotification implements ShouldQueue
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
        $user = $event->order->user;

        if (!$user || $user->type !== UserType::USER->value) {
            return;
        }

        $user->notify(new UserPaymentSucceededNotification($event->order));
    }
}

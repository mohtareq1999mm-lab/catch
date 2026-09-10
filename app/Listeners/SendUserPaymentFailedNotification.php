<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\PaymentFailed;
use App\Notifications\UserPaymentFailedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendUserPaymentFailedNotification implements ShouldQueue
{
    public $queue = 'meem-medium';

    public function handle(PaymentFailed $event): void
    {
        $user = $event->order->user;

        if (!$user || $user->type !== UserType::USER->value) {
            return;
        }

        $user->notify(new UserPaymentFailedNotification($event->order));
    }
}

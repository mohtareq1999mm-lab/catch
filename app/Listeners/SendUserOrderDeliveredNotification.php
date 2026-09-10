<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Notifications\UserOrderDeliveredNotification;
use App\Events\OrderDelivered;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendUserOrderDeliveredNotification implements ShouldQueue
{
    public $queue = 'meem-medium';

    public function handle(OrderDelivered $event): void
    {
        $user = $event->order->user;

        if (!$user || $user->type !== UserType::USER->value) {
            return;
        }

        $user->notify(new UserOrderDeliveredNotification($event->order));
    }
}

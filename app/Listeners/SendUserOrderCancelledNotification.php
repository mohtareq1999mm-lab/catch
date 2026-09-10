<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\OrderCancelled;
use App\Notifications\UserOrderCancelledNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendUserOrderCancelledNotification implements ShouldQueue
{
    public $queue = 'meem-medium';

    public function handle(OrderCancelled $event): void
    {
        $user = $event->order->user;

        if (!$user || $user->type !== UserType::USER->value) {
            return;
        }

        $user->notify(new UserOrderCancelledNotification($event->order));
    }
}

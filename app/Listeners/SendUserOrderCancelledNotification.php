<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\OrderCancelled;
use App\Notifications\UserOrderCancelledNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendUserOrderCancelledNotification implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    public function handle(OrderCancelled $event): void
    {
        $user = $event->order->user;

        if (!$user || $user->type !== UserType::USER->value) {
            return;
        }

        $user->notify(new UserOrderCancelledNotification($event->order));
    }
}

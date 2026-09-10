<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\OrderCreated;
use App\Notifications\UserOrderCreatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendUserOrderCreatedNotification implements ShouldQueue
{
    public $queue = 'meem-medium';

    public function handle(OrderCreated $event): void
    {
        $user = $event->order->user;

        if (!$user || $user->type !== UserType::USER->value) {
            return;
        }

        $user->notify(new UserOrderCreatedNotification($event->order));
    }
}

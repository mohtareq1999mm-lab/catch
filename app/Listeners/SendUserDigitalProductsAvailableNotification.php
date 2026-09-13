<?php

namespace App\Listeners;

use App\Events\DigitalProductsDelivered;
use App\Notifications\UserDigitalProductsAvailableNotification;
use App\Enums\UserType;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendUserDigitalProductsAvailableNotification implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    public function handle(DigitalProductsDelivered $event): void
    {
        $user = $event->order->user;

        if (!$user || $user->type !== UserType::USER->value) {
            return;
        }

        $delivered = $event->entitlements->filter(
            fn ($e) => $e->status === \App\Models\DigitalEntitlement::STATUS_DELIVERED
        );

        if ($delivered->isEmpty()) {
            return;
        }

        $user->notify(new UserDigitalProductsAvailableNotification($event->order, $delivered));
    }
}

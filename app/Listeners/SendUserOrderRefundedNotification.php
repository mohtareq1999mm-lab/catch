<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\RefundApproved;
use App\Notifications\UserOrderRefundedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendUserOrderRefundedNotification implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    public function handle(RefundApproved $event): void
    {
        $user = $event->refund->customer;

        if (!$user || $user->type !== UserType::USER->value) {
            return;
        }

        $user->notify(new UserOrderRefundedNotification($event->refund));
    }
}

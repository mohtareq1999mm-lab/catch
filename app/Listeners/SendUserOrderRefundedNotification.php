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
        // Phase 10 unification: the refunds ledger keys the customer by
        // user_id (customer_id is a legacy read alias and is null on new
        // rows). Fall back so approval notifications actually deliver.
        $user = $event->refund->customer
            ?? ($event->refund->user_id
                ? \Marvel\Database\Models\User::query()->whereKey($event->refund->user_id)->first()
                : null);

        if (!$user || $user->type !== UserType::USER->value) {
            return;
        }

        $user->notify(new UserOrderRefundedNotification($event->refund));
    }
}

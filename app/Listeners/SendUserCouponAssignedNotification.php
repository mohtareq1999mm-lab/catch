<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\CouponAssigned;
use App\Notifications\UserCouponAssignedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendUserCouponAssignedNotification implements ShouldQueue
{
    public $queue = 'meem-medium';

    public function handle(CouponAssigned $event): void
    {
        $user = $event->assignment->user;

        if (!$user || $user->type !== UserType::USER->value) {
            return;
        }

        $user->notify(new UserCouponAssignedNotification($event->assignment));
    }
}

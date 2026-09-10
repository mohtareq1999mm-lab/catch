<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\AssignedCouponConsumed;
use App\Notifications\UserCouponUsedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendUserCouponUsedNotification implements ShouldQueue
{
    public $queue = 'meem-medium';

    public function handle(AssignedCouponConsumed $event): void
    {
        $user = $event->user;

        if (!$user || $user->type !== UserType::USER->value) {
            return;
        }

        $user->notify(new UserCouponUsedNotification(
            $event->coupon,
            $event->couponAssignment,
            $event->user,
            $event->order,
            $event->remainingUses,
            $event->consumedAt,
        ));
    }
}

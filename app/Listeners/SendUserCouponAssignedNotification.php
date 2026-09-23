<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\CouponAssigned;
use App\Notifications\UserCouponAssignedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendUserCouponAssignedNotification implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    public function handle(CouponAssigned $event): void
    {
        $user = $event->assignment->user;

        if (!$user || $user->type !== UserType::USER->value) {
            // Observable skip: non-customer assignees (e.g. admins) never
            // receive coupon.assigned by design — log it so missing
            // notifications are diagnosable instead of silent.
            Log::info('coupon.assigned.skipped_non_user', [
                'coupon_id' => $event->assignment->coupon_id,
                'assignment_id' => $event->assignment->getKey(),
                'user_id' => $event->assignment->user_id,
            ]);

            return;
        }

        $user->notify(new UserCouponAssignedNotification($event->assignment));
    }
}

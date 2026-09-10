<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\CouponCreated;
use App\Notifications\UserCouponAvailableNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendUserCouponAvailableNotification implements ShouldQueue
{
    public $queue = 'meem-medium';

    public function handle(CouponCreated $event): void
    {
        $coupon = $event->coupon;

        // Only broadcast to everyone when the coupon is public (no per-user assignments).
        // Coupons that get assigned later notify their specific users via CouponAssigned.
        if ($coupon->assignments()->exists()) {
            return;
        }

        $userModel = config('auth.providers.users.model');

        $userModel::query()
            ->where('type', UserType::USER->value)
            ->chunkById(500, function ($users) use ($coupon) {
                foreach ($users as $user) {
                    $user->notify(new UserCouponAvailableNotification($coupon));
                }
            });
    }
}

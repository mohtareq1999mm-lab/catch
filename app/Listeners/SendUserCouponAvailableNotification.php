<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\CouponCreated;
use App\Notifications\UserCouponAvailableNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendUserCouponAvailableNotification implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    public function handle(CouponCreated $event): void
    {
        $this->sendIfMaturePublic($event->coupon);
    }

    /**
     * Global fan-out, fail-closed. Sends ONLY when the coupon is proven
     * publicly discoverable: the explicit is_public flag, or (legacy) no
     * assignments — AND no targeting AND past the creation grace window
     * (targeting is added after creation in the admin flow; a fresh
     * coupon without targeting may still become targeted). The public
     * sweep (coupons:detect-public) delivers mature public coupons.
     */
    public function sendIfMaturePublic(\Marvel\Database\Models\Coupon $coupon): bool
    {
        // Assignments never demote publicity: only a private (flag off)
        // assigned coupon is refused here.
        if (! (bool) ($coupon->getAttribute('is_public') ?? false)
            && $coupon->assignments()->exists()) {
            return false;
        }

        try {
            $targeting = $coupon->targeting;
        } catch (\Throwable) {
            return false;
        }

        if ($targeting !== null) {
            // Any targeting row (any mode) routes through the targeted
            // plane or assignment path — never the global broadcast.
            return false;
        }

        // Authoritative business delay (public_grace_minutes, default 4 min).
        $graceMinutes = max(1, (int) config('coupon-distribution.public_grace_minutes', 4));

        if ($coupon->created_at !== null && $coupon->created_at->diffInMinutes(now()) < $graceMinutes) {
            // Too fresh to prove public — the sweep delivers it once mature.
            return false;
        }

        $userModel = config('auth.providers.users.model');

        $userModel::query()
            ->where('type', UserType::USER->value)
            ->chunkById(500, function ($users) use ($coupon) {
                foreach ($users as $user) {
                    $user->notify(new UserCouponAvailableNotification($coupon));
                }
            });

        return true;
    }
}

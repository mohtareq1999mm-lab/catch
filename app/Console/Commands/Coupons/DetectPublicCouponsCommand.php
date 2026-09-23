<?php

namespace App\Console\Commands\Coupons;

use App\Listeners\SendUserCouponAvailableNotification;
use Illuminate\Console\Command;
use Marvel\Database\Models\Coupon;

/**
 * Mature-public global fan-out sweep.
 *
 * The creation-time listener defers fresh coupons (targeting may still be
 * added — fail-closed against the global code leak). This sweep delivers
 * coupons that stayed publicly discoverable past the grace window:
 * explicit is_public flag, or (legacy) still no assignments — and still
 * no targeting. Dedupe: skips coupons that already fanned out
 * (a coupon.available notification row exists for the coupon).
 */
class DetectPublicCouponsCommand extends Command
{
    protected $signature = 'coupons:detect-public';

    protected $description = 'Globally announce mature public coupons (past creation grace, still untargeted/unassigned).';

    public function handle(SendUserCouponAvailableNotification $listener): int
    {
        $graceMinutes = max(1, (int) config('coupon-distribution.public_grace_minutes', 4));
        $cutoff = now()->subMinutes($graceMinutes);

        $coupons = Coupon::query()
            ->where('created_at', '<', $cutoff)
            ->where(function ($q) {
                // Publicly discoverable (explicit flag survives assignments)
                // or legacy public (no assignments). Targeting rows are
                // always excluded below.
                $q->whereDoesntHave('assignments')->orWhere('is_public', true);
            })
            ->whereDoesntHave('targeting')
            ->cursor();

        $announced = 0;

        foreach ($coupons as $coupon) {
            if ($this->alreadyAnnounced($coupon->getKey())) {
                continue;
            }

            if ($listener->sendIfMaturePublic($coupon)) {
                $announced++;
            }
        }

        $this->info("Public sweep complete: {$announced} coupon(s) announced.");

        return self::SUCCESS;
    }

    private function alreadyAnnounced(int $couponId): bool
    {
        // data is TEXT: anchor on "resource_id":<id>, (trailing comma) to
        // avoid prefix collisions between ids.
        return \Illuminate\Support\Facades\DB::table('notifications')
            ->where('type', 'coupon.available')
            ->where('data', 'like', '%"resource_id":'.$couponId.',%')
            ->exists();
    }
}

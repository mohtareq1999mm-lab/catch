<?php

namespace App\Console\Commands\Coupons;

use App\Events\Coupons\CouponExpired;
use Illuminate\Console\Command;
use Marvel\Database\Models\Coupon;

/**
 * Expiry scan: coupons past end_date cancel in-flight targeted runs via
 * the CouponExpired event. Already-delivered notifications are kept.
 */
class DetectCouponExpiryCommand extends Command
{
    protected $signature = 'coupons:detect-expiry';

    protected $description = 'Cancel in-flight distribution runs for expired coupons.';

    public function handle(): int
    {
        // Only coupons with actual in-flight runs signal expiry — the scan
        // stays quiet instead of re-firing for every long-expired coupon.
        $couponIds = \App\Models\CouponDistributionRun::query()
            ->whereIn('status', ['pending', 'running'])
            ->distinct()
            ->pluck('coupon_id');

        if ($couponIds->isEmpty()) {
            $this->info('Expiry scan complete: no in-flight runs.');

            return self::SUCCESS;
        }

        $coupons = Coupon::query()
            ->whereIn('id', $couponIds)
            ->whereDate('end_date', '<', today())
            ->with('targeting')
            ->cursor();

        $count = 0;

        foreach ($coupons as $coupon) {
            event(new CouponExpired($coupon));
            $count++;
        }

        $this->info("Expiry scan complete: {$count} coupon(s) signalled.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Listeners\Coupons;

use App\Enums\CouponDistributionRunStatus;
use App\Enums\QueueName;
use App\Events\Coupons\CouponDisabled;
use App\Events\Coupons\CouponExpired;
use App\Models\CouponDistributionRun;
use App\Services\Coupon\Distribution\DistributionRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Disable/expiry → cancel in-flight runs so consumers stop harmlessly.
 * Already-delivered database notifications are NOT retroactively deleted.
 */
class CancelCouponDistribution implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return QueueName::high();
    }

    public function handle(CouponDisabled|CouponExpired $event): void
    {
        $runs = CouponDistributionRun::query()
            ->where('coupon_id', $event->coupon->getKey())
            ->whereIn('status', [
                CouponDistributionRunStatus::PENDING->value,
                CouponDistributionRunStatus::RUNNING->value,
            ])
            ->get();

        $service = app(DistributionRunService::class);

        foreach ($runs as $run) {
            $service->finish($run, \App\Enums\CouponDistributionRunStatus::CANCELLED);
        }

        Log::info('coupon.distribution.runs_cancelled', [
            'coupon_id' => $event->coupon->getKey(),
            'runs' => $runs->count(),
        ]);
    }
}

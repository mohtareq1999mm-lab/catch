<?php

namespace App\Listeners\Coupons;

use App\Enums\CouponDistributionTriggerType;
use App\Enums\QueueName;
use App\Events\Coupons\CouponActivated;
use App\Events\Coupons\CouponTargetingChanged;
use App\Services\Coupon\Distribution\DistributionService;
use App\Services\Coupon\Distribution\NonDistributableCouponException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Coupon-level triggers → full-audience distribution runs.
 * Dedupe keys converge observer + scheduler + manual double-fires.
 */
class StartCouponDistribution implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return QueueName::high();
    }

    public function handle(CouponActivated|CouponTargetingChanged $event): void
    {
        $trigger = $event instanceof CouponActivated
            ? CouponDistributionTriggerType::COUPON_ACTIVATED
            : CouponDistributionTriggerType::TARGETING_CHANGED;

        try {
            app(DistributionService::class)->startDistribution(
                $event->coupon->fresh('targeting'),
                $trigger,
                'activation',
            );
        } catch (NonDistributableCouponException $e) {
            // Expected: targeting removed or mode not dynamic (e.g. coupon
            // returned to always-eligible). Nothing to distribute — no retry.
            Log::info('coupon.trigger.skipped_non_distributable', [
                'coupon_id' => $event->coupon->getKey(),
                'trigger' => $trigger->value,
            ]);
        } catch (\Throwable $e) {
            Log::warning('coupon.trigger.distribution_failed', [
                'coupon_id' => $event->coupon->getKey(),
                'trigger' => $trigger->value,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

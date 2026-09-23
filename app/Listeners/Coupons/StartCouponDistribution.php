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
            // A coupon deleted after the event fired leaves nothing to
            // distribute: drop the job instead of retrying a ghost.
            $coupon = $event->coupon->fresh('targeting');

            if ($coupon === null) {
                Log::info('coupon.trigger.skipped_deleted_coupon', [
                    'coupon_id' => $event->coupon->getKey(),
                    'trigger' => $trigger->value,
                ]);

                return;
            }

            // Trigger-driven fan-out waits the authoritative business delay
            // (coupon-distribution.distribution_delay_seconds, default 4 min).
            // The coupon itself is valid immediately. The consumer reloads
            // current coupon state at execution; stale runs abort on tree
            // drift, and edits schedule fresh delayed runs.
            $delaySeconds = max(0, (int) config('coupon-distribution.distribution_delay_seconds', 240));

            app(DistributionService::class)->startDistribution(
                $coupon,
                $trigger,
                'activation',
                null,
                null,
                null,
                $delaySeconds,
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

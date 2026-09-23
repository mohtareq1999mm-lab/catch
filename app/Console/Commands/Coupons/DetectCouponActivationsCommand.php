<?php

namespace App\Console\Commands\Coupons;

use App\Enums\CouponDistributionTriggerType;
use App\Services\Coupon\Distribution\DistributionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Coupon;

/**
 * Time-based activation: coupons whose start_date has arrived (status=true)
 * get a deduplicated distribution run. Never fans out synchronously — the
 * run dedupe key converges repeated scheduler ticks to a single run.
 */
class DetectCouponActivationsCommand extends Command
{
    protected $signature = 'coupons:detect-activations';

    protected $description = 'Start distribution runs for newly-active coupons (start_date reached). Deduplicated.';

    public function handle(DistributionService $distributions): int
    {
        $coupons = Coupon::query()
            ->where('status', true)
            // NULL start_date = immediately active; a start_date in the
            // past entered validity. Dedupe converges repeat ticks.
            ->where(function ($q) {
                $q->whereNull('start_date')
                    ->orWhereDate('start_date', '<=', today());
            })
            ->whereHas('targeting', static function ($q) {
                $q->whereIn('mode', ['dynamic', 'assignment_and_dynamic', 'assignment_or_dynamic']);
            })
            ->with('targeting')
            ->get();

        $started = 0;

        foreach ($coupons as $coupon) {
            try {
                $result = $distributions->startDistribution(
                    $coupon,
                    CouponDistributionTriggerType::COUPON_ACTIVATED,
                    'activation',
                );

                if ($result['created']) {
                    $started++;
                }
            } catch (\Throwable $e) {
                Log::warning('coupon.activation.detection_failed', [
                    'coupon_id' => $coupon->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Activation scan complete: {$started} new run(s).");

        return self::SUCCESS;
    }
}

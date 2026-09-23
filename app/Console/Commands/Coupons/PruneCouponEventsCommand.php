<?php

namespace App\Console\Commands\Coupons;

use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Messaging\RabbitMqTopology;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retention prune for coupon event infrastructure (outbox published rows,
 * completed event logs, aged observability-only published logs).
 * Failed/dead-lettered rows are NEVER pruned here — operators resolve them
 * first. Work-event `published` rows (start/chunk/evaluate/notify-requested
 * never consumed) are RETAINED: they are stuck-pipeline evidence.
 */
class PruneCouponEventsCommand extends Command
{
    protected $signature = 'coupons:prune-events {--days=90 : Delete published/completed rows older than N days}';

    protected $description = 'Prune aged published outbox rows and completed event logs (failures retained).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $outbox = DB::table('coupon_outbox')
            ->where('status', 'published')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $logs = DB::table('coupon_event_logs')
            ->where('status', 'completed')
            ->where('created_at', '<', $cutoff)
            ->delete();

        // Observability-only events (became_eligible, notification.sent,
        // lifecycle completions) have no consumer and stay `published`
        // forever — prune those by age. Fail-closed: only KNOWN
        // observability types are pruned; work events AND unknown/future
        // types are retained (unknown rows are stuck-pipeline evidence until
        // a binding proves them harmless).
        $workTypes = collect(RabbitMqTopology::bindings())->flatten()->all();
        $prunableTypes = array_values(array_diff(
            CouponDistributionEvents::all(), $workTypes));

        $observability = DB::table('coupon_event_logs')
            ->where('status', 'published')
            ->where('created_at', '<', $cutoff)
            ->whereIn('event_type', $prunableTypes)
            ->delete();

        $this->info("Pruned {$outbox} outbox row(s), {$logs} completed log(s), {$observability} observability log(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}

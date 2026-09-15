<?php

namespace App\Console\Commands;

use App\Audit\ActivityAuditService;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

class PruneActivityLog extends Command
{
    protected $signature = 'activitylog:prune
                            {--days=90 : Retain this many days of activity log}
                            {--chunk=1000 : Number of rows deleted per batch}
                            {--dry-run : Report without deleting}';

    protected $description = 'Delete activity log entries older than the retention period in chunks.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days') ?: (int) config('activitylog.retention_days', 90));
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $deleted = 0;

        do {
            $ids = Activity::where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            if (!$dryRun) {
                Activity::whereIn('id', $ids)->delete();
            }

            $deleted += $ids->count();
        } while ($ids->count() === $chunk);

        if (!$dryRun && $deleted > 0) {
            ActivityAuditService::recordBatch(
                'activity_log',
                'activity_pruned',
                __('activity.activity_pruned'),
                context: ['source' => 'scheduler'],
                properties: [
                    'retention_days' => $days,
                    'cutoff' => $cutoff->toDateTimeString(),
                    'deleted_count' => $deleted,
                ],
            );
        }

        $this->info(sprintf(
            'Pruned %d activity log entr%s older than %s%s.',
            $deleted,
            $deleted === 1 ? 'y' : 'ies',
            $cutoff->toDateTimeString(),
            $dryRun ? ' (dry run)' : ''
        ));

        return self::SUCCESS;
    }
}

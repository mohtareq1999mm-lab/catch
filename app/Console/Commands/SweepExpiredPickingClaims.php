<?php

namespace App\Console\Commands;

use App\Services\Fulfillment\PickingExecutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 15: release picking claims whose lease expired (workers that
 * disconnected mid-task return their tasks to the pool; picked progress
 * is preserved for resume).
 */
class SweepExpiredPickingClaims extends Command
{
    protected $signature = 'picking:sweep-expired-claims {--limit=100}';
    protected $description = 'Release expired picking-task claims back to the pool';

    public function handle(PickingExecutionService $execution): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $released = $execution->sweepExpiredClaims($limit);

        Log::info('Expired picking claims swept', ['released' => $released, 'limit' => $limit]);
        $this->info("Released {$released} expired claim(s).");

        return self::SUCCESS;
    }
}

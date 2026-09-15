<?php

namespace App\Jobs;

use App\Audit\ActivityAuditService;
use App\Audit\ActivitySnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class LogActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ActivitySnapshot $snapshot)
    {
        $this->onQueue(config('queue.queues.medium'));
    }

    public function handle(): void
    {
        // The snapshot is self-contained: subject existence is irrelevant.
        ActivityAuditService::record($this->snapshot);
    }
}

<?php

namespace Tests\Feature\Phase0;

use App\Enums\QueueName;
use Illuminate\Queue\Events\JobFailed;
use Tests\TestCase;

/**
 * Phase 0 infrastructure pins: queue-name canonicalization, failed-job
 * observability wiring, pruning schedule, and the webhook dispatch fix.
 */
class InfrastructureHardeningTest extends TestCase
{
    /** @test */
    public function queue_name_enum_matches_worker_topology_exactly()
    {
        // These strings are consumed by deploy/supervisor/*.conf workers — only meem-high and meem-medium are canonical.
        $this->assertSame('meem-high', QueueName::HIGH->value);
        $this->assertSame('meem-medium', QueueName::MEDIUM->value);
        $this->assertCount(2, QueueName::cases(), 'Only meem-high and meem-medium should exist');
    }

    /** @test */
    public function job_failed_event_has_a_registered_listener()
    {
        $listeners = app('events')->getListeners(JobFailed::class);

        $this->assertGreaterThanOrEqual(1, count($listeners));
    }

    /** @test */
    public function failed_job_pruning_is_scheduled()
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

        $pruneEvents = collect($schedule->events())->filter(
            fn ($e) => str_contains($e->command ?? '', 'queue:prune-failed')
        );

        $this->assertTrue($pruneEvents->isNotEmpty(), 'queue:prune-failed must be scheduled');

        // dailyAt('03:15') => cron "15 3 * * *"
        $this->assertTrue(
            $pruneEvents->contains(fn ($e) => str_ends_with(trim($e->expression), '* * *')),
            'pruning should run daily'
        );
    }

    /** @test */
    public function frontend_cache_invalidation_no_longer_dispatches_inline()
    {
        // P5: the listener must hand off to the queue, never execute inline.
        $source = file_get_contents(app_path('Listeners/DispatchFrontendCacheInvalidation.php'));

        $this->assertStringNotContainsString('dispatchSync', $source);
        $this->assertStringContainsString('SendFrontendWebhookJob::dispatch(', $source);
    }
}

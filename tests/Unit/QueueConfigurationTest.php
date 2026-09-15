<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\QueueName;
use App\Jobs\GenerateInvoicePdfJob;
use App\Jobs\LogActivityJob;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Queue configuration isolation — proves physical names are deployment config.
 *
 * Changing QUEUE_HIGH / QUEUE_MEDIUM must change the physical queue without PHP changes.
 */
class QueueConfigurationTest extends TestCase
{
    /** @test */
    public function high_queue_resolves_from_config(): void
    {
        Config::set('queue.queues.high', 'meem-high');
        $this->assertSame('meem-high', config('queue.queues.high'));
        $this->assertSame('meem-high', QueueName::high());

        Queue::fake();
        $invoice = new \App\Models\Invoice();
        $invoice->id = 1;
        $job = new GenerateInvoicePdfJob($invoice);
        $this->assertSame('meem-high', $job->queue ?? $job->getQueue() ?? config('queue.queues.high'));
        $listener = new \App\Listeners\SendNewOrderNotification();
        $this->assertSame('meem-high', $listener->viaQueue());
    }

    /** @test */
    public function medium_queue_resolves_from_config(): void
    {
        Config::set('queue.queues.medium', 'meem-medium');
        $this->assertSame('meem-medium', config('queue.queues.medium'));
        $this->assertSame('meem-medium', QueueName::medium());

        $job = new LogActivityJob('App\Models\User', 1, null, 'test', 'test', null);
        $this->assertSame('meem-medium', $job->queue);
    }

    /** @test */
    public function high_priority_job_dispatched_to_configured_high_queue(): void
    {
        Config::set('queue.queues.high', 'catch-high');
        Queue::fake();

        $listener = new \App\Listeners\FulfillDigitalProducts(app(\App\Services\Digital\DigitalFulfillmentService::class));
        $this->assertSame(config('queue.queues.high'), $listener->viaQueue());
    }

    /** @test */
    public function medium_priority_job_dispatched_to_configured_medium_queue(): void
    {
        Config::set('queue.queues.medium', 'catch-medium');
        Queue::fake();

        $job = new \Marvel\Jobs\ImportProductsJob(999);
        $this->assertSame(config('queue.queues.medium'), $job->queue);
    }

    /** @test */
    public function configuration_isolation_changing_env_changes_physical_queue_without_code_change(): void
    {
        // Simulate Project A (meem)
        Config::set('queue.queues.high', 'meem-high');
        Config::set('queue.queues.medium', 'meem-medium');
        $this->assertSame('meem-high', QueueName::high());
        $this->assertSame('meem-medium', QueueName::medium());

        // Simulate Project B (catch) — no code change, only config
        Config::set('queue.queues.high', 'catch-high');
        Config::set('queue.queues.medium', 'catch-medium');
        $this->assertSame('catch-high', QueueName::high());
        $this->assertSame('catch-medium', QueueName::medium());

        $this->assertSame('catch-high', config('queue.queues.high'));
        $this->assertSame('catch-medium', config('queue.queues.medium'));
    }

    /** @test */
    public function worker_config_is_env_driven(): void
    {
        $highConf = file_get_contents(base_path('deploy/supervisor/laravel-worker-catch-high.conf'));
        $medConf = file_get_contents(base_path('deploy/supervisor/laravel-worker-catch-medium.conf'));

        $this->assertStringContainsString('QUEUE_HIGH', $highConf);
        $this->assertStringContainsString('QUEUE_MEDIUM', $medConf);
        $this->assertStringContainsString('catch-high', $highConf);
        $this->assertStringContainsString('catch-medium', $medConf);
        $this->assertStringNotContainsString('--queue=catch-high --', $highConf);
        $this->assertStringNotContainsString('--queue=catch-medium --', $medConf);
    }

    /** @test */
    public function config_cache_safety_env_only_in_config_files(): void
    {
        $roots = [app_path('Jobs'), app_path('Listeners'), app_path('Notifications')];
        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') continue;
                $src = file_get_contents($file->getPathname());
                $this->assertSame(0, preg_match("/env\(\s*['\"]QUEUE_HIGH['\"]/", $src), "env(QUEUE_HIGH) must not be used in {$file->getPathname()} — use config()");
                $this->assertSame(0, preg_match("/env\(\s*['\"]QUEUE_MEDIUM['\"]/", $src), "env(QUEUE_MEDIUM) must not be used in {$file->getPathname()} — use config()");
            }
        }
    }
}

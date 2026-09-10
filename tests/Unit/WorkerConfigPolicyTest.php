<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Queue worker configuration policy (closure gate):
 *  - meem-high: tries=5, timeout=1200, sleep=1, queue=meem-high ONLY.
 *  - meem-medium: tries=3, timeout=900, queues=meem-medium,default.
 *  - No active worker may remain at --timeout=90.
 *
 * These are the repository-owned deployment artifacts; production process
 * state is verified separately on the server (see closure report).
 */
class WorkerConfigPolicyTest extends TestCase
{
    private function readConf(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/deploy/supervisor/' . $file;

        $this->assertFileExists($path, "Missing supervisor config: {$file}");

        return (string) file_get_contents($path);
    }

    private function commandLine(string $conf): string
    {
        foreach (preg_split('/\r?\n/', $conf) ?: [] as $line) {
            if (str_starts_with(trim($line), 'command=')) {
                return trim($line);
            }
        }

        $this->fail('No command= line found in supervisor config.');
    }

    public function test_meem_high_worker_matches_policy(): void
    {
        $cmd = $this->commandLine($this->readConf('laravel-worker-meem-high.conf'));

        $this->assertStringContainsString('--queue=meem-high', $cmd);
        $this->assertStringContainsString('--tries=5', $cmd);
        $this->assertStringContainsString('--timeout=1200', $cmd);
        $this->assertStringContainsString('--sleep=1', $cmd);
    }

    public function test_meem_medium_worker_matches_policy(): void
    {
        $cmd = $this->commandLine($this->readConf('laravel-worker-meem-medium.conf'));

        $this->assertStringContainsString('--queue=meem-medium,default', $cmd);
        $this->assertStringContainsString('--tries=3', $cmd);
        $this->assertStringContainsString('--timeout=900', $cmd);
    }

    public function test_no_worker_remains_at_timeout_90(): void
    {
        foreach (['laravel-worker-meem-high.conf', 'laravel-worker-meem-medium.conf'] as $file) {
            $conf = $this->readConf($file);

            $this->assertSame(
                0,
                preg_match('/--timeout=90(?!\d)/', $conf),
                "{$file} still contains the removed --timeout=90 default."
            );
        }
    }

    public function test_retry_after_exceeds_highest_job_timeout(): void
    {
        $queueConfig = (string) file_get_contents(dirname(__DIR__, 2) . '/config/queue.php');

        // Extract the retry_after that belongs to the active `database`
        // connection block (the driver used by the supervisor workers).
        $this->assertSame(
            1,
            preg_match("/'database'\s*=>\s*\[.*?'retry_after'\s*=>\s*(\d+)/s", $queueConfig, $m),
            'database connection retry_after not found in queue config.'
        );

        $this->assertGreaterThanOrEqual(
            1500,
            (int) $m[1],
            'retry_after must exceed ImportProductsJob timeout (1500s) to prevent duplicate execution.'
        );
    }
}

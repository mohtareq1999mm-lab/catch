<?php

namespace Tests\Feature\Guest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real parallel cancellation proof (#82, spec #73).
 *
 * Two OS processes, each with its own MySQL connection, race the SAME valid
 * cancellation link through GuestCancellationService. The child orchestrator
 * (tests/Concurrency/parallel_cancellation_proof.php) owns the fixtures (they
 * must be committed for other connections to see them), spawns both probes,
 * asserts the exactly-once outcome and cleans up — this wrapper pins PROOF_OK.
 *
 * MySQL only: FOR UPDATE is a no-op elsewhere and cross-process state needs a
 * shared database.
 */
class GuestCancellationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Guest cancellation concurrency requires MySQL (FOR UPDATE + shared storage).');
        }
    }

    /** @test */
    public function two_parallel_cancellations_cancel_exactly_once(): void
    {
        $script = base_path('tests/Concurrency/parallel_cancellation_proof.php');

        if (!is_file($script)) {
            $this->markTestSkipped('Parallel-cancellation orchestrator script not present.');
        }

        // The orchestrator inherits this process's DB_* env and re-exports it
        // to the probes, so all three target the same migrated MySQL test DB.
        $process = new Process([PHP_BINARY, $script], base_path(), [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) config('database.connections.mysql.host'),
            'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => (string) config('database.connections.mysql.database'),
            'DB_USERNAME' => (string) config('database.connections.mysql.username'),
            'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
        ]);
        $process->setTimeout(300);
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertEquals(0, $process->getExitCode(), "Orchestrator failed:\n{$output}");
        $this->assertStringContainsString('PROOF_OK', $output, "Expected PROOF_OK, got:\n{$output}");
    }
}

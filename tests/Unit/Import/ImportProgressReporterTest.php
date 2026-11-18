<?php

namespace Tests\Unit\Import;

use App\Events\FileOperationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Enums\FileOperationType;
use Marvel\Services\Import\ImportProgressReporter;
use Marvel\Services\Import\RunOutcome;
use Tests\TestCase;

/**
 * Pins the one progress module for the import family: the reporter is
 * parameterized with the family's progress event + channel and protocol, so
 * category/brand run the milestone/threshold cadence through the same module
 * the product family runs its smooth protocol through.
 *
 * External behaviour only: the Import row and the dispatched
 * FileOperationEvent payload — no private-method reach-ins.
 */
class ImportProgressReporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        // The reporter writes heartbeat signal files; start from a clean dir.
        $dir = storage_path('app/imports');
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    private function fakeBroadcasts(): void
    {
        config(['app.env' => 'production', 'shop.pusher.enabled' => true]);
        Event::fake([FileOperationEvent::class]);
    }

    private function createUser(): User
    {
        return User::create([
            'name' => 'Progress User',
            'email' => 'progress-' . uniqid() . '@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'user',
        ]);
    }

    private function createImport(User $user): Import
    {
        return Import::create([
            'type' => FileOperationType::CATEGORY_IMPORT,
            'file_path' => 'imports/test.xlsx',
            'file_name' => 'test.xlsx',
            'status' => 'processing',
            'total_rows' => 0,
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
            'created_by' => $user->id,
        ]);
    }

    private function thresholdReporter(Import $import, RunOutcome $outcome): ImportProgressReporter
    {
        return new ImportProgressReporter(
            $outcome,
            (int) $import->id,
            FileOperationEvent::CATEGORY_IMPORT_PROGRESS,
            FileOperationType::CATEGORY_IMPORT,
            ImportProgressReporter::PROTOCOL_THRESHOLD,
        );
    }

    private function progressBroadcasts(): array
    {
        return collect(Event::dispatched(
            FileOperationEvent::class,
            fn (FileOperationEvent $event) => $event->eventName === FileOperationEvent::CATEGORY_IMPORT_PROGRESS
        ))->map(fn (array $arguments) => $arguments[0])->all();
    }

    public function test_threshold_explicit_milestone_broadcasts_the_family_event_with_counters(): void
    {
        $user = $this->createUser();
        $import = $this->createImport($user);
        $this->fakeBroadcasts();

        $outcome = RunOutcome::fromCounts(4, 1, 0);
        $this->thresholdReporter($import, $outcome)->explicit(60.0);

        Event::assertDispatched(function (FileOperationEvent $event) use ($import) {
            return $event->eventName === FileOperationEvent::CATEGORY_IMPORT_PROGRESS
                && $event->userId === $import->created_by
                && $event->payload['kind'] === 'category-import'
                && $event->payload['progress'] === 60.0
                && $event->payload['processed_rows'] === 5
                && $event->payload['success_rows'] === 4
                && $event->payload['failed_rows'] === 1;
        });
    }

    public function test_threshold_tick_publishes_only_on_threshold_multiples_and_keeps_the_milestone_progress(): void
    {
        $user = $this->createUser();
        $import = $this->createImport($user);
        $this->fakeBroadcasts();

        $outcome = new RunOutcome(fn () => 0, fn () => []);
        $reporter = $this->thresholdReporter($import, $outcome);

        for ($i = 0; $i < 19; $i++) {
            $outcome->recordSuccess();
        }

        $reporter->explicit(60.0);
        $this->assertCount(1, $this->progressBroadcasts(), 'The milestone itself broadcasts once.');

        $reporter->flush();
        $this->assertCount(1, $this->progressBroadcasts(), 'processed=19 must not tick.');

        $outcome->recordSuccess();
        $reporter->flush();

        $broadcasts = $this->progressBroadcasts();
        $this->assertCount(2, $broadcasts, 'processed=20 must tick.');
        $this->assertSame(
            60.0,
            $broadcasts[1]->payload['progress'],
            'Ticks keep the last explicit milestone as the reported progress.'
        );
    }

    public function test_threshold_finalize_writes_the_three_uncapped_counter_keys_and_publishes_100(): void
    {
        $user = $this->createUser();
        $import = $this->createImport($user);
        $this->fakeBroadcasts();

        // processed (30) deliberately exceeds total_rows (12): the family
        // finalize write is uncapped, unlike the product rowAttributes shape.
        $outcome = RunOutcome::fromCounts(25, 5, 12);
        $this->thresholdReporter($import, $outcome)->finalize();

        $import->refresh();

        $this->assertSame(30, $import->processed_rows);
        $this->assertSame(25, $import->success_rows);
        $this->assertSame(5, $import->failed_rows);
        $this->assertNull($import->errors, 'The family finalize write must not touch the error list.');

        $broadcasts = $this->progressBroadcasts();
        $this->assertCount(1, $broadcasts);
        $this->assertSame(100.0, $broadcasts[0]->payload['progress']);
        $this->assertSame('category-import', $broadcasts[0]->payload['kind']);
    }

    public function test_smooth_finalize_keeps_the_product_row_shape_and_stays_silent(): void
    {
        $user = $this->createUser();
        $import = $this->createImport($user);
        $this->fakeBroadcasts();

        $outcome = RunOutcome::fromCounts(10, 2, 12, [[
            'sheet' => 'products',
            'row' => 1,
            'sku' => 'SKU-A',
            'error_message' => 'row failed',
        ]]);
        (new ImportProgressReporter($outcome, (int) $import->id))->finalize();

        $import->refresh();

        $this->assertSame(12, $import->processed_rows);
        $this->assertSame(10, $import->success_rows);
        $this->assertSame(2, $import->failed_rows);
        $this->assertNotEmpty($import->errors, 'The product shape owns the error list.');
        $this->assertSame('SKU-A', $import->errors[0]['sku']);

        Event::assertNothingDispatched();
    }

    public function test_reporter_without_an_import_id_is_a_silent_no_op(): void
    {
        $this->fakeBroadcasts();

        $outcome = RunOutcome::fromCounts(1, 0, 0);
        $reporter = new ImportProgressReporter($outcome, null);

        $reporter->explicit(50.0);
        $reporter->flush();
        $reporter->finalize();

        Event::assertNothingDispatched();
    }
}

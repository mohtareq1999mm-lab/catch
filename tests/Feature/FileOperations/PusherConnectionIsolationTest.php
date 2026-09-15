<?php

declare(strict_types=1);

namespace Tests\Feature\FileOperations;

use App\Events\CategoryImportProgress;
use App\Events\FileOperationEvent;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Tests\Stubs\RecordingPusher;

/**
 * Regression for "first event via web (pusher) succeeds, subsequent via worker (log) lost".
 *
 * Root cause: LogBroadcaster log line "Broadcasting [...] on channels [private-users.1]"
 * proves worker default was log, not pusher. Fix: FileOperationEvent forces pusher
 * via broadcastConnections(), so it never inherits a stale/log default.
 */
class PusherConnectionIsolationTest extends FileOperationBroadcastTestCase
{
    public function test_file_operation_event_forces_pusher_connection(): void
    {
        $event = new FileOperationEvent(1, FileOperationEvent::PRODUCT_IMPORT_PROGRESS, ['progress' => 42]);

        $this->assertTrue(method_exists($event, 'broadcastConnections'));
        $this->assertSame(['pusher'], $event->broadcastConnections());
    }

    public function test_category_import_progress_forces_pusher_connection(): void
    {
        $event = new CategoryImportProgress(1, 1, ['progress' => 10]);

        $this->assertSame(['pusher'], $event->broadcastConnections());
    }

    public function test_progress_still_reaches_pusher_when_default_is_log(): void
    {
        // Simulate worker with stale config where default is log
        config(['broadcasting.default' => 'log']);

        // Re-install RecordingPusher on the pusher broadcaster (not log)
        $broadcaster = Broadcast::driver('pusher');
        $this->assertInstanceOf(PusherBroadcaster::class, $broadcaster);
        $recorder = new RecordingPusher();
        $broadcaster->setPusher($recorder);
        config(['app.env' => 'local']);

        $user = $this->createOwnerUser();
        $import = $this->createOperation($user, 'product', 'processing', 100);

        // Dispatch via trait helper that uses FileOperationEvent::dispatch
        // Must still hit pusher, not log, even though default is log.
        $service = new \Marvel\Services\Import\ProductImportService($import->id);
        $service->writeExplicitProgress(55.0);

        // Because event forces pusher, RecordingPusher must have captured it
        $matches = array_values(array_filter(
            $recorder->broadcasts,
            fn (array $b) => in_array('private-users.' . $user->id, $b['channels'], true)
                && $b['event'] === FileOperationEvent::PRODUCT_IMPORT_PROGRESS
        ));

        $this->assertNotEmpty($matches, 'Progress must reach pusher even when default is log. LogBroadcaster must not swallow it.');
        $this->assertSame(55.0, $matches[0]['data']['progress']);
    }

    public function test_terminal_still_reaches_pusher_when_default_is_log(): void
    {
        config(['broadcasting.default' => 'log']);
        $broadcaster = Broadcast::driver('pusher');
        $recorder = new RecordingPusher();
        $broadcaster->setPusher($recorder);
        config(['app.env' => 'local']);

        $user = $this->createOwnerUser();
        $import = $this->createOperation($user, 'category', 'processing', 10);

        $job = new \Marvel\Jobs\ExportCategoriesJob($import->id);
        // Simulate terminal via trait directly
        $ref = new \ReflectionClass($job);
        $method = $ref->getMethod('broadcastFileOperationTerminal');
        $method->setAccessible(true);
        $method->invoke($job, FileOperationEvent::CATEGORY_EXPORT_COMPLETED, 'category-export', $import->id, 'completed', false, [
            'progress' => 100.0,
            'total_rows' => 10,
            'processed_rows' => 10,
            'success_rows' => 10,
            'failed_rows' => 0,
            'download_available' => true,
        ]);

        $matches = array_values(array_filter(
            $recorder->broadcasts,
            fn (array $b) => $b['event'] === FileOperationEvent::CATEGORY_EXPORT_COMPLETED
        ));
        $this->assertNotEmpty($matches, 'Terminal must reach pusher even when default is log');
        $this->assertSame('completed', $matches[0]['data']['status']);
    }
}

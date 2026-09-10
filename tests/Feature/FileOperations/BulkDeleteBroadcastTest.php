<?php

declare(strict_types=1);

namespace Tests\Feature\FileOperations;

use App\Events\FileOperationEvent;
use Marvel\Database\Models\Category;
use Marvel\Jobs\BulkDeleteCategoriesJob;

/**
 * Realtime contract for Category Bulk Delete:
 * per-chunk progress events, terminal completed / cancelled / failed events,
 * and exactly-once terminal emission.
 */
class BulkDeleteBroadcastTest extends FileOperationBroadcastTestCase
{
    protected function writeIdsSignal(int $operationId, array $ids): void
    {
        file_put_contents(
            storage_path("app/imports/ids_{$operationId}.json"),
            json_encode(['ids' => $ids]),
            LOCK_EX
        );
    }

    protected function writeCancelSignal(int $operationId): void
    {
        file_put_contents(
            storage_path("app/imports/cancel_{$operationId}.json"),
            json_encode(['cancelled_at' => now()->toIso8601String()]),
            LOCK_EX
        );
    }

    public function test_progress_and_completed_events_are_broadcast(): void
    {
        $user = $this->createOwnerUser();

        $a = Category::create(['name' => ['en' => 'A'], 'slug' => 'a']);
        $b = Category::create(['name' => ['en' => 'B'], 'slug' => 'b']);

        $import = $this->createOperation($user, 'category-bulk-delete', 'pending', 2);
        $this->writeIdsSignal($import->id, [$a->id, $b->id]);

        (new BulkDeleteCategoriesJob($import->id))->handle();

        $this->assertDatabaseHas('imports', ['id' => $import->id, 'status' => 'completed']);

        $progress = $this->broadcastsTo(FileOperationEvent::CATEGORY_BULK_DELETE_PROGRESS, $user->id);
        $this->assertNotEmpty($progress, 'Chunk progress must be broadcast.');
        $this->assertSame('category-bulk-delete', $progress[0]['data']['kind']);

        $terminal = $this->assertBroadcast(FileOperationEvent::CATEGORY_BULK_DELETE_COMPLETED, $user->id);
        $this->assertSame('completed', $terminal['data']['status']);
        $this->assertFalse($terminal['data']['has_errors']);
        $this->assertPayloadIsSafe($terminal['data']);
    }

    public function test_cancelled_terminal_is_broadcast_once(): void
    {
        $user = $this->createOwnerUser();

        $a = Category::create(['name' => ['en' => 'A'], 'slug' => 'cancel-a']);

        $import = $this->createOperation($user, 'category-bulk-delete', 'pending', 1);
        $this->writeIdsSignal($import->id, [$a->id]);
        $this->writeCancelSignal($import->id);

        (new BulkDeleteCategoriesJob($import->id))->handle();

        $this->assertDatabaseHas('imports', ['id' => $import->id, 'status' => 'cancelled']);

        $cancelled = $this->assertBroadcast(FileOperationEvent::CATEGORY_BULK_DELETE_CANCELLED, $user->id);
        $this->assertSame('cancelled', $cancelled['data']['status']);

        $this->assertCount(
            1,
            $this->pusher->broadcasts,
            'Pre-processing cancellation must emit only the single cancelled terminal event.'
        );

        foreach ($this->pusher->broadcasts as $broadcast) {
            $this->assertNotEquals(FileOperationEvent::CATEGORY_BULK_DELETE_COMPLETED, $broadcast['event']);
            $this->assertNotEquals(FileOperationEvent::CATEGORY_BULK_DELETE_PROGRESS, $broadcast['event']);
        }
    }

    public function test_failed_hook_broadcasts_once_on_transition(): void
    {
        $user = $this->createOwnerUser();
        $import = $this->createOperation($user, 'category-bulk-delete', 'processing', 3);

        $job = new BulkDeleteCategoriesJob($import->id);
        $job->failed(new \Exception('bulk delete crashed'));

        $this->assertDatabaseHas('imports', ['id' => $import->id, 'status' => 'failed']);

        $terminal = $this->assertBroadcast(FileOperationEvent::CATEGORY_BULK_DELETE_FAILED, $user->id);
        $this->assertSame('failed', $terminal['data']['status']);
        $this->assertTrue($terminal['data']['has_errors']);

        $countBefore = count($this->pusher->broadcasts);
        $job->failed(new \Exception('again'));
        $this->assertCount($countBefore, $this->pusher->broadcasts, 'Terminal event emitted twice.');
    }
}

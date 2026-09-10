<?php

declare(strict_types=1);

namespace Tests\Feature\FileOperations;

use App\Events\FileOperationEvent;
use Illuminate\Support\Facades\Log;
use Marvel\Services\Import\ProductImportService;

/**
 * Realtime progress + terminal contract for Product Import.
 *
 * - writeExplicitProgress / throttled flush ticks broadcast
 *   product.import.progress to the owner's private channel.
 * - Terminal transitions (completed_with_errors / failed / cancelled) are
 *   carried on the same event name with an explicit `status`.
 * - failed() hooks emit exactly once; retries hitting a terminal state
 *   early-return without broadcasting.
 */
class ProductImportBroadcastTest extends FileOperationBroadcastTestCase
{
    public function test_write_explicit_progress_broadcasts_to_owner_channel(): void
    {
        $user = $this->createOwnerUser();
        $import = $this->createOperation($user, 'product', 'processing', 100);

        $service = new ProductImportService($import->id);
        $service->writeExplicitProgress(42.0);

        $broadcast = $this->assertBroadcast(FileOperationEvent::PRODUCT_IMPORT_PROGRESS, $user->id);

        $this->assertSame(42.0, $broadcast['data']['progress']);
        $this->assertSame('product-import', $broadcast['data']['kind']);
        $this->assertSame($import->id, $broadcast['data']['id']);
        $this->assertSame('processing', $broadcast['data']['status']);
        $this->assertSame(0, $broadcast['data']['processed_rows']);
        $this->assertPayloadIsSafe($broadcast['data']);
    }

    public function test_progress_event_is_not_sent_to_anyone_but_the_owner(): void
    {
        $user = $this->createOwnerUser();
        $stranger = $this->createOwnerUser();
        $import = $this->createOperation($user, 'product', 'processing');

        (new ProductImportService($import->id))->writeExplicitProgress(10.0);

        $this->assertBroadcast(FileOperationEvent::PRODUCT_IMPORT_PROGRESS, $user->id);
        $this->assertNoBroadcastTo($stranger->id);
    }

    public function test_failed_hook_broadcasts_terminal_once(): void
    {
        $user = $this->createOwnerUser();
        $import = $this->createOperation($user, 'product', 'processing');

        $job = new \Marvel\Jobs\ImportProductsJob($import->id);
        $job->failed(new \Exception('worker crashed'));

        $this->assertDatabaseHas('imports', ['id' => $import->id, 'status' => 'failed']);

        $terminal = $this->assertBroadcast(FileOperationEvent::PRODUCT_IMPORT_PROGRESS, $user->id);
        $this->assertSame('failed', $terminal['data']['status']);
        $this->assertTrue($terminal['data']['has_errors']);

        $countBefore = count($this->pusher->broadcasts);
        $job->failed(new \Exception('second call must not re-broadcast'));
        $this->assertCount($countBefore, $this->pusher->broadcasts, 'Terminal event was emitted twice.');
    }

    public function test_retry_on_terminal_state_does_not_broadcast_again(): void
    {
        $user = $this->createOwnerUser();
        $import = $this->createOperation($user, 'product', 'completed', 5);

        $job = new \Marvel\Jobs\ImportProductsJob($import->id);
        $job->handle();

        $this->assertSame([], $this->pusher->broadcasts, 'Terminal early-return must not broadcast.');
        $this->assertDatabaseHas('imports', ['id' => $import->id, 'status' => 'completed']);
    }

    public function test_no_broadcast_when_operation_has_no_owner(): void
    {
        $import = $this->createOperation($this->createOwnerUser(), 'product', 'processing');
        $orphan = new ProductImportService();
        $orphan->writeExplicitProgress(50.0);

        $this->assertNoBroadcastTo($import->created_by);
    }

    public function test_dispatch_is_logged_only_after_a_real_dispatch(): void
    {
        Log::spy();

        $user = $this->createOwnerUser();
        $import = $this->createOperation($user, 'product', 'processing');

        (new ProductImportService($import->id))->writeExplicitProgress(25.0);

        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $channel, array $ctx = []) => $channel === 'file-operation.event.dispatched'
                && ($ctx['operation_id'] ?? null) === $import->id
        );
    }
}

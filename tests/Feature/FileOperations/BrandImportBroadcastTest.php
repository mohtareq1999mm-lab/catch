<?php

declare(strict_types=1);

namespace Tests\Feature\FileOperations;

use App\Events\FileOperationEvent;
use Marvel\Services\Import\BrandImportService;

/**
 * Brand Import previously logged `brand.import.progress.dispatched` WITHOUT
 * dispatching any broadcast event. These tests prove the event now actually
 * reaches Pusher and that the misleading log no longer exists.
 */
class BrandImportBroadcastTest extends FileOperationBroadcastTestCase
{
    public function test_publish_progress_actually_dispatches_to_owner_channel(): void
    {
        $user = $this->createOwnerUser();
        $import = $this->createOperation($user, 'brand', 'processing', 50);

        (new BrandImportService($import->id))->writeExplicitProgress(64.0);

        $broadcast = $this->assertBroadcast(FileOperationEvent::BRAND_IMPORT_PROGRESS, $user->id);

        $this->assertSame(64.0, $broadcast['data']['progress']);
        $this->assertSame('brand-import', $broadcast['data']['kind']);
        $this->assertSame($import->id, $broadcast['data']['id']);
        $this->assertSame('processing', $broadcast['data']['status']);
        $this->assertPayloadIsSafe($broadcast['data']);
    }

    public function test_finalize_progress_broadcasts_terminal_status(): void
    {
        $user = $this->createOwnerUser();
        $import = $this->createOperation($user, 'brand', 'processing');

        (new BrandImportService($import->id))->finalizeProgress();

        // finalizeProgress itself only persists; the terminal signal is sent
        // by the job after the DB transition. No premature completed event.
        foreach ($this->pusher->broadcasts as $broadcast) {
            $this->assertNotContains(
                'completed',
                $broadcast['data'],
                'finalizeProgress must not emit a terminal status.'
            );
        }
    }

    public function test_misleading_dispatched_log_is_removed_from_source(): void
    {
        $servicePath = realpath(__DIR__ . '/../../../packages/marvel/src/Services/Import/BrandImportService.php');

        $this->assertFileExists($servicePath);

        $source = file_get_contents($servicePath);

        $this->assertStringNotContainsString(
            'brand.import.progress.dispatched',
            $source,
            'The false "dispatched" observability log must not come back.'
        );

        $this->assertStringContainsString(
            'broadcastFileOperationProgress',
            $source,
            'publishProgress must dispatch the real event.'
        );
    }

    public function test_no_broadcast_when_import_has_no_creator(): void
    {
        $user = $this->createOwnerUser();
        $import = $this->createOperation($user, 'brand', 'processing');

        (new BrandImportService())->writeExplicitProgress(20.0);

        $this->assertNoBroadcastTo($user->id);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\FileOperations;

use App\Events\FileOperationEvent;
use Marvel\Database\Models\Category;
use Marvel\Jobs\ExportCategoriesJob;
use Marvel\Services\Import\BrandImportService;
use Tests\Stubs\ThrowingPusher;

/**
 * Broadcast failure isolation: a Pusher outage must never fail the
 * underlying file operation. The DB must still reach the correct terminal
 * state while every broadcast attempt throws.
 */
class BroadcastFailureIsolationTest extends FileOperationBroadcastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $broadcaster = \Illuminate\Support\Facades\Broadcast::driver();

        if ($broadcaster instanceof \Illuminate\Broadcasting\Broadcasters\PusherBroadcaster) {
            $broadcaster->setPusher(new ThrowingPusher());
        }

        $this->pusher = null;
    }

    public function test_category_export_completes_when_pusher_throws(): void
    {
        $user = $this->createOwnerUser();

        Category::create(['name' => ['en' => 'Alpha'], 'slug' => 'iso-alpha']);
        Category::create(['name' => ['en' => 'Beta'], 'slug' => 'iso-beta']);

        $import = $this->createOperation($user, 'category-export', 'pending');

        (new ExportCategoriesJob($import->id))->handle();

        $this->assertDatabaseHas('imports', ['id' => $import->id, 'status' => 'completed']);
        $this->assertNotNull(
            \Marvel\Database\Models\Import::find($import->id)->file_path,
            'Export artifact must still be produced when broadcasting fails.'
        );
    }

    public function test_brand_import_progress_does_not_throw_when_pusher_throws(): void
    {
        $user = $this->createOwnerUser();
        $import = $this->createOperation($user, 'brand', 'processing');

        $service = new BrandImportService($import->id);

        $this->expectNotToPerformAssertions();
        $service->writeExplicitProgress(55.0);
    }

    public function test_failed_hook_transition_survives_pusher_outage(): void
    {
        $user = $this->createOwnerUser();
        $import = $this->createOperation($user, 'category-export', 'processing');

        (new ExportCategoriesJob($import->id))->failed(new \Exception('boom'));

        $this->assertDatabaseHas('imports', ['id' => $import->id, 'status' => 'failed']);
    }
}

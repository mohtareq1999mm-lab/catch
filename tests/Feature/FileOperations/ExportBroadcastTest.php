<?php

declare(strict_types=1);

namespace Tests\Feature\FileOperations;

use App\Events\FileOperationEvent;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Brand;
use Marvel\Jobs\ExportBrandsJob;
use Marvel\Jobs\ExportCategoriesJob;

/**
 * Terminal realtime events for queued exports. Exports have no incremental
 * progress; they emit a single terminal event after the DB transition:
 *   DB update → Pusher event.
 */
class ExportBroadcastTest extends FileOperationBroadcastTestCase
{
    public function test_category_export_broadcasts_completed_after_db_transition(): void
    {
        $user = $this->createOwnerUser();

        Category::create(['name' => ['en' => 'Alpha'], 'slug' => 'alpha']);
        Category::create(['name' => ['en' => 'Beta'], 'slug' => 'beta']);

        $import = $this->createOperation($user, 'category-export', 'pending');

        (new ExportCategoriesJob($import->id))->handle();

        $this->assertDatabaseHas('imports', ['id' => $import->id, 'status' => 'completed']);

        $terminal = $this->assertBroadcast(FileOperationEvent::CATEGORY_EXPORT_COMPLETED, $user->id);

        $this->assertSame('category-export', $terminal['data']['kind']);
        $this->assertSame('completed', $terminal['data']['status']);
        $this->assertFalse($terminal['data']['has_errors']);
        $this->assertSame(100.0, $terminal['data']['progress']);
        $this->assertGreaterThanOrEqual(2, $terminal['data']['total_rows']);
    }

    public function test_category_export_failed_hook_broadcasts_once_on_transition(): void
    {
        $user = $this->createOwnerUser();
        $import = $this->createOperation($user, 'category-export', 'processing');

        $job = new ExportCategoriesJob($import->id);
        $job->failed(new \Exception('export exploded'));

        $this->assertDatabaseHas('imports', ['id' => $import->id, 'status' => 'failed']);

        $terminal = $this->assertBroadcast(FileOperationEvent::CATEGORY_EXPORT_FAILED, $user->id);
        $this->assertSame('failed', $terminal['data']['status']);

        $countBefore = count($this->pusher->broadcasts);
        $job->failed(new \Exception('again'));
        $this->assertCount($countBefore, $this->pusher->broadcasts);
    }

    public function test_brand_export_broadcasts_completed_after_db_transition(): void
    {
        $user = $this->createOwnerUser();

        Brand::create(['name' => ['en' => 'Acme'], 'slug' => 'acme']);
        Brand::create(['name' => ['en' => 'Globex'], 'slug' => 'globex']);

        $import = $this->createOperation($user, 'brand-export', 'pending');

        (new ExportBrandsJob($import->id))->handle();

        $this->assertDatabaseHas('imports', ['id' => $import->id, 'status' => 'completed']);

        $terminal = $this->assertBroadcast(FileOperationEvent::BRAND_EXPORT_COMPLETED, $user->id);

        $this->assertSame('brand-export', $terminal['data']['kind']);
        $this->assertSame('completed', $terminal['data']['status']);
        $this->assertFalse($terminal['data']['has_errors']);
    }

    public function test_brand_export_failed_hook_broadcasts_once_on_transition(): void
    {
        $user = $this->createOwnerUser();
        $import = $this->createOperation($user, 'brand-export', 'processing');

        $job = new ExportBrandsJob($import->id);
        $job->failed(new \Exception('brand export exploded'));

        $this->assertDatabaseHas('imports', ['id' => $import->id, 'status' => 'failed']);

        $terminal = $this->assertBroadcast(FileOperationEvent::BRAND_EXPORT_FAILED, $user->id);
        $this->assertSame('failed', $terminal['data']['status']);
    }

    public function test_terminal_export_does_not_re_broadcast_on_retry(): void
    {
        $user = $this->createOwnerUser();
        $import = $this->createOperation($user, 'category-export', 'completed');

        (new ExportCategoriesJob($import->id))->handle();

        $this->assertSame(
            [],
            $this->pusher->broadcasts,
            'Terminal early-return must not emit duplicate events on retry.'
        );
    }
}

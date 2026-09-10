<?php

namespace Tests\Feature\Categories;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Marvel\Jobs\BulkDeleteCategoriesJob;
use Marvel\Jobs\ExportCategoriesJob;
use Marvel\Jobs\ImportCategoriesJob;
use Tests\TestCase;

/**
 * CATEGORY I — JOBS / QUEUES
 *
 * Audit gap: no test in the suite asserted the ACTUAL queue names.
 * Verified from source (constructors):
 *   ImportCategoriesJob::onQueue('meem-high')   (line 33)
 *   ExportCategoriesJob::onQueue('meem-high')   (line 27)
 *   BulkDeleteCategoriesJob::onQueue('meem-high') (line 31)
 */
class CategoryQueueAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Storage::fake('public');
        Queue::fake();
    }

    public function test_import_job_is_queued_on_meem_high(): void
    {
        $this->superAdmin();

        $file = UploadedFile::fake()->create(
            'categories.xlsx', 100,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $this->postJson(self::PREFIX . '/categories/import', ['file' => $file])
            ->assertStatus(202);

        Queue::assertPushedOn('meem-high', ImportCategoriesJob::class);
        Queue::assertPushed(ImportCategoriesJob::class, 1);
    }

    public function test_export_job_is_queued_on_meem_high(): void
    {
        $this->superAdmin();

        $this->getJson(self::PREFIX . '/categories/export')
            ->assertStatus(202);

        Queue::assertPushedOn('meem-high', ExportCategoriesJob::class);
        Queue::assertPushed(ExportCategoriesJob::class, 1);
    }

    public function test_bulk_delete_job_is_queued_on_meem_high(): void
    {
        $this->superAdmin();

        // BulkDeleteCategoriesRequest validates ids.* exists — use a real row.
        $categoryId = DB::table('categories')->insertGetId([
            'name' => json_encode(['en' => 'Queue Probe']),
            'slug' => 'queue-probe-' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson(self::PREFIX . '/categories/bulk-delete', ['ids' => [$categoryId]])
            ->assertStatus(202);

        Queue::assertPushedOn('meem-high', BulkDeleteCategoriesJob::class);
        Queue::assertPushed(BulkDeleteCategoriesJob::class, 1);
    }

    private function superAdmin(): object
    {
        $permissionClass = \Marvel\Enums\Permission::class;
        $roleClass = \Marvel\Enums\Role::class;

        foreach ([$permissionClass::SUPER_ADMIN, $permissionClass::DELETE_CATEGORY] as $perm) {
            \Spatie\Permission\Models\Permission::findOrCreate($perm, self::GUARD);
        }

        $role = \Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => $roleClass::SUPER_ADMIN, 'guard_name' => self::GUARD],
            ['display_name' => 'Super Admin']
        );
        $role->givePermissionTo([$permissionClass::SUPER_ADMIN, $permissionClass::DELETE_CATEGORY]);

        $user = \Marvel\Database\Models\User::create([
            'name' => 'QA Super Admin',
            'email' => uniqid() . '-qa-super@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
            'type' => 'admin',
        ]);
        $user->assignRole($roleClass::SUPER_ADMIN);
        $user->givePermissionTo([$permissionClass::SUPER_ADMIN, $permissionClass::DELETE_CATEGORY]);

        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
        return $user;
    }
}

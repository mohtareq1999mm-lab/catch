<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Marvel\Jobs\BulkDeleteCategoriesJob;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CategoryBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->adminUser = $this->createSuperAdminUser();

        $dir = storage_path('app/imports');
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    private function createSuperAdminUser(): User
    {
        $permissions = [
            PermissionEnum::DELETE_CATEGORY];

        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, self::GUARD);
        }

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'guard_name' => self::GUARD,
            'display_name' => 'Super Admin']);

        foreach ($permissions as $perm) {
            $role->givePermissionTo($perm);
        }

        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin']);

        $user->assignRole($role);

        foreach ($permissions as $perm) {
            $user->givePermissionTo($perm);
        }

        return $user;
    }

    private function makeImport(array $ids, string $status = 'pending'): Import
    {
        $import = Import::create([
            'type' => 'category-bulk-delete',
            'file_path' => '',
            'file_name' => '',
            'status' => $status,
            'total_rows' => count($ids),
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
            'created_by' => $this->adminUser->id]);

        $dir = storage_path('app/imports');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        file_put_contents($dir . "/ids_{$import->id}.json", json_encode(['ids' => $ids]), LOCK_EX);

        return $import;
    }

    public function test_unauthenticated_user_cannot_bulk_delete(): void
    {
        $response = $this->postJson(self::PREFIX . '/categories/bulk-delete', ['ids' => [1]]);

        $response->assertUnauthorized();
    }

    public function test_bulk_delete_validates_ids_required(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->postJson(self::PREFIX . '/categories/bulk-delete', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ids']);
    }

    public function test_bulk_delete_validates_empty_ids_array(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->postJson(self::PREFIX . '/categories/bulk-delete', ['ids' => []]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ids']);
    }

    public function test_bulk_delete_dispatches_job_and_returns_202(): void
    {
        Queue::fake();

        Sanctum::actingAs($this->adminUser);

        $category = Category::create(['name' => ['en' => 'To Delete'], 'slug' => 'to-delete']);

        $response = $this->postJson(self::PREFIX . '/categories/bulk-delete', ['ids' => [$category->id]]);

        $response->assertStatus(202);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['data' => ['bulk_delete_id', 'status']]);

        $this->assertDatabaseHas('imports', [
            'id' => $response->json('data.bulk_delete_id'),
            'type' => 'category-bulk-delete',
            'status' => 'pending']);

        Queue::assertPushed(BulkDeleteCategoriesJob::class);
    }

    public function test_job_soft_deletes_leaf_categories(): void
    {
        $a = Category::create(['name' => ['en' => 'A'], 'slug' => 'a']);
        $b = Category::create(['name' => ['en' => 'B'], 'slug' => 'b']);

        $import = $this->makeImport([$a->id, $b->id]);

        $job = new BulkDeleteCategoriesJob($import->id);
        $job->handle();

        $import->refresh();

        $this->assertEquals('completed', $import->status);
        $this->assertEquals(2, $import->success_rows);
        $this->assertEquals(0, $import->failed_rows);
        $this->assertSoftDeleted('categories', ['id' => $a->id]);
        $this->assertSoftDeleted('categories', ['id' => $b->id]);
    }

    public function test_job_skips_categories_with_children(): void
    {
        $parent = Category::create(['name' => ['en' => 'Parent'], 'slug' => 'parent']);
        $child = Category::create(['name' => ['en' => 'Child'], 'slug' => 'child', 'parent_id' => $parent->id]);

        $import = $this->makeImport([$parent->id]);

        $job = new BulkDeleteCategoriesJob($import->id);
        $job->handle();

        $import->refresh();

        $this->assertEquals('failed', $import->status);
        $this->assertEquals(0, $import->success_rows);
        $this->assertEquals(1, $import->failed_rows);
        $this->assertNotSoftDeleted('categories', ['id' => $parent->id]);
        $this->assertNotSoftDeleted('categories', ['id' => $child->id]);
        $this->assertSame($parent->id, $import->errors[0]['category_id']);
    }

    public function test_job_reports_missing_ids_as_errors(): void
    {
        $existing = Category::create(['name' => ['en' => 'Existing'], 'slug' => 'existing']);

        $import = $this->makeImport([$existing->id, 99999]);

        $job = new BulkDeleteCategoriesJob($import->id);
        $job->handle();

        $import->refresh();

        $this->assertEquals('completed_with_errors', $import->status);
        $this->assertEquals(1, $import->success_rows);
        $this->assertEquals(1, $import->failed_rows);
        $this->assertSoftDeleted('categories', ['id' => $existing->id]);
        $this->assertSame(99999, $import->errors[0]['category_id']);
    }

    public function test_job_skips_already_deleted_ids_on_retry(): void
    {
        $category = Category::create(['name' => ['en' => 'Retry'], 'slug' => 'retry']);
        $category->delete();

        $import = $this->makeImport([$category->id]);

        $job = new BulkDeleteCategoriesJob($import->id);
        $job->handle();

        $import->refresh();

        $this->assertEquals('completed', $import->status);
        $this->assertEquals(1, $import->success_rows);
        $this->assertEquals(0, $import->failed_rows);
    }

    public function test_job_deletes_children_before_parents(): void
    {
        $parent = Category::create(['name' => ['en' => 'Parent'], 'slug' => 'parent']);
        $child = Category::create(['name' => ['en' => 'Child'], 'slug' => 'child', 'parent_id' => $parent->id]);

        $import = $this->makeImport([$parent->id, $child->id]);

        $job = new BulkDeleteCategoriesJob($import->id);
        $job->handle();

        $import->refresh();

        $this->assertEquals('completed', $import->status);
        $this->assertEquals(2, $import->success_rows);
        $this->assertSoftDeleted('categories', ['id' => $parent->id]);
        $this->assertSoftDeleted('categories', ['id' => $child->id]);
    }

    public function test_status_endpoint_returns_progress(): void
    {
        Sanctum::actingAs($this->adminUser);

        $import = Import::create([
            'type' => 'category-bulk-delete',
            'file_path' => '',
            'file_name' => '',
            'status' => 'processing',
            'total_rows' => 2,
            'processed_rows' => 1,
            'success_rows' => 1,
            'failed_rows' => 0,
            'created_by' => $this->adminUser->id]);

        $response = $this->getJson(self::PREFIX . "/categories/bulk-delete/{$import->id}");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'processing');
        $response->assertJsonPath('data.successful_rows', 1);
    }

    public function test_cannot_bulk_delete_without_permission(): void
    {
        $permissions = [PermissionEnum::VIEW_USERS];
        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, self::GUARD);
        }

        $role = Role::create([
            'name' => 'admin-no-delete',
            'guard_name' => self::GUARD,
            'display_name' => 'Admin No Delete']);

        $user = User::create([
            'name' => 'Limited Admin',
            'email' => 'limited@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin']);

        $user->assignRole($role);

        Sanctum::actingAs($user);

        $response = $this->postJson(self::PREFIX . '/categories/bulk-delete', ['ids' => [1]]);

        $response->assertForbidden();
    }
}
<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CategoryPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    private const CATEGORY_IMPORT_EXPORT_PERMISSIONS = [
        'import-category',
        'export-category'];

    private const PERMISSION_LABELS = [
        'import-category' => ['en' => 'Import categories', 'ar' => 'استيراد التصنيفات'],
        'export-category' => ['en' => 'Export categories', 'ar' => 'تصدير التصنيفات']];

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
    }

    private function createUserWithPermissions(array $permissions, string $type = 'admin'): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, self::GUARD);
        }

        $role = Role::create([
            'name' => 'test-role-' . uniqid(),
            'guard_name' => self::GUARD,
            'display_name' => json_encode(['en' => 'Test Role'])]);

        $role->givePermissionTo($permissions);

        $user = User::create([
            'name' => 'Test Admin',
            'email' => 'admin-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => $type]);

        $user->assignRole($role);

        return $user;
    }

    private function createAuthenticatedCustomer(): User
    {
        $user = User::create([
            'name' => 'Test Customer',
            'email' => 'customer-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'user']);

        Sanctum::actingAs($user);

        return $user;
    }

    private function createImport(array $overrides = []): Import
    {
        return Import::create(array_merge([
            'type' => 'category',
            'file_path' => 'imports/test.xlsx',
            'file_name' => 'test.xlsx',
            'status' => 'processing',
            'total_rows' => 10,
            'processed_rows' => 5,
            'success_rows' => 5,
            'failed_rows' => 0,
            'created_by' => 1], $overrides));
    }

    private function sampleFile(): UploadedFile
    {
        return UploadedFile::fake()->create(
            'categories.xlsx',
            100,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    /** @test */
    public function permission_enum_contains_category_import_export_permissions(): void
    {
        $this->assertSame('import-category', PermissionEnum::IMPORT_CATEGORY);
        $this->assertSame('export-category', PermissionEnum::EXPORT_CATEGORY);
        $this->assertSame('super_admin');
    }

    /** @test */
    public function permission_seeder_creates_both_permissions_for_api_guard(): void
    {
        $this->seed(PermissionSeeder::class);

        foreach (self::CATEGORY_IMPORT_EXPORT_PERMISSIONS as $name) {
            $this->assertDatabaseHas('permissions', [
                'name' => $name,
                'guard_name' => self::GUARD]);
        }
    }

    /** @test */
    public function permission_seeder_does_not_create_duplicate_records(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(PermissionSeeder::class);

        foreach (self::CATEGORY_IMPORT_EXPORT_PERMISSIONS as $name) {
            $count = DB::table('permissions')
                ->where('name', $name)
                ->where('guard_name', self::GUARD)
                ->count();

            $this->assertSame(1, $count, "Permission {$name} must exist exactly once after re-seeding");
        }
    }

    /** @test */
    public function super_admin_role_receives_both_permissions_from_seeder(): void
    {
        $this->seed(PermissionSeeder::class);

        $role = Role::query()->where('name', RoleEnum::SUPER_ADMIN)->where('guard_name', self::GUARD)->first();

        $this->assertNotNull($role);

        foreach (self::CATEGORY_IMPORT_EXPORT_PERMISSIONS as $name) {
            $this->assertTrue(
                $role->hasPermissionTo($name, self::GUARD),
                "super_admin must have permission {$name}"
            );
        }
    }

    /** @test */
    public function both_permissions_have_english_and_arabic_translations(): void
    {
        foreach (self::PERMISSION_LABELS as $name => $labels) {
            foreach (['en', 'ar'] as $locale) {
                Lang::setLocale($locale);

                $resolved = __("permissions.{$name}");
                $this->assertNotSame(
                    "permissions.{$name}",
                    $resolved,
                    "Translation for {$name} in {$locale} must not fall back to the raw key"
                );

                $this->assertSame($labels[$locale], $resolved, "Unexpected label for {$name} in {$locale}");
            }
        }
    }

    /** @test */
    public function permission_resource_exposes_translated_labels(): void
    {
        $this->seed(PermissionSeeder::class);

        foreach (self::CATEGORY_IMPORT_EXPORT_PERMISSIONS as $name) {
            $permission = Permission::query()->where('name', $name)->where('guard_name', self::GUARD)->first();

            $this->assertNotNull($permission);

            $resource = (new \Marvel\Http\Resources\PermissionResource($permission))
                ->resolve(request());

            $this->assertSame(
                self::PERMISSION_LABELS[$name]['en'],
                $resource['label'],
                "Label for {$name} in default locale (en)"
            );
        }
    }

    /** @test */
    public function unauthenticated_requests_are_rejected_with_401(): void
    {
        Queue::fake();
        Storage::fake('public');

        $this->postJson(self::PREFIX . '/categories/import', ['file' => $this->sampleFile()])->assertStatus(401);
        $this->getJson(self::PREFIX . '/categories/import/sample')->assertStatus(401);
        $this->getJson(self::PREFIX . '/categories/import/1')->assertStatus(401);
        $this->postJson(self::PREFIX . '/categories/import/1/cancel')->assertStatus(401);
        $this->getJson(self::PREFIX . '/categories/import/1/download-errors')->assertStatus(401);

        $this->getJson(self::PREFIX . '/categories/export')->assertStatus(401);
        $this->getJson(self::PREFIX . '/categories/export/1')->assertStatus(401);
        $this->getJson(self::PREFIX . '/categories/export/1/download')->assertStatus(401);
    }

    /** @test */
    public function customer_without_permissions_is_forbidden_on_all_endpoints(): void
    {
        Queue::fake();
        Storage::fake('public');

        $this->createAuthenticatedCustomer();

        $this->postJson(self::PREFIX . '/categories/import', ['file' => $this->sampleFile()])->assertStatus(403);
        $this->getJson(self::PREFIX . '/categories/import/sample')->assertStatus(403);
        $this->getJson(self::PREFIX . '/categories/import/1')->assertStatus(403);
        $this->postJson(self::PREFIX . '/categories/import/1/cancel')->assertStatus(403);
        $this->getJson(self::PREFIX . '/categories/import/1/download-errors')->assertStatus(403);

        $this->getJson(self::PREFIX . '/categories/export')->assertStatus(403);
        $this->getJson(self::PREFIX . '/categories/export/1')->assertStatus(403);
        $this->getJson(self::PREFIX . '/categories/export/1/download')->assertStatus(403);
    }

    /** @test */
    public function admin_without_any_permission_is_forbidden(): void
    {
        Queue::fake();
        Storage::fake('public');

        $user = $this->createUserWithPermissions([], 'admin');
        Sanctum::actingAs($user);

        $this->postJson(self::PREFIX . '/categories/import', ['file' => $this->sampleFile()])->assertStatus(403);
        $this->getJson(self::PREFIX . '/categories/export')->assertStatus(403);
    }

    /** @test */
    public function admin_with_only_import_permission_can_use_import_but_not_export(): void
    {
        Queue::fake();
        Storage::fake('public');

        $user = $this->createUserWithPermissions(['import-category'], 'admin');
        Sanctum::actingAs($user);

        $this->getJson(self::PREFIX . '/categories/import/sample')->assertStatus(200);

        $response = $this->postJson(self::PREFIX . '/categories/import', ['file' => $this->sampleFile()]);
        $response->assertStatus(202);
        $importId = $response->json('data.import_id');

        $this->getJson(self::PREFIX . "/categories/import/{$importId}")->assertStatus(200);
        $this->postJson(self::PREFIX . "/categories/import/{$importId}/cancel")->assertStatus(200);

        $import = Import::findOrFail($importId);
        $this->assertSame('cancelled', $import->status);

        $this->getJson(self::PREFIX . '/categories/export')->assertStatus(403);
    }

    /** @test */
    public function admin_with_only_export_permission_can_use_export_but_not_import(): void
    {
        Queue::fake();
        Storage::fake('public');

        $user = $this->createUserWithPermissions(['export-category'], 'admin');
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/categories/export');
        $response->assertStatus(202);
        $exportId = $response->json('data.export_id');

        $this->getJson(self::PREFIX . "/categories/export/{$exportId}")->assertStatus(200);

        $this->postJson(self::PREFIX . '/categories/import', ['file' => $this->sampleFile()])->assertStatus(403);
        $this->getJson(self::PREFIX . '/categories/import/sample')->assertStatus(403);
    }

    /** @test */
    public function import_permission_cannot_download_export_and_export_permission_cannot_download_errors(): void
    {
        Queue::fake();
        Storage::fake('public');

        $importOnly = $this->createUserWithPermissions(['import-category'], 'admin');
        Sanctum::actingAs($importOnly);

        $this->getJson(self::PREFIX . '/categories/export/1/download')->assertStatus(403);

        $exportOnly = $this->createUserWithPermissions(['export-category'], 'admin');
        Sanctum::actingAs($exportOnly);

        $import = $this->createImport(['errors' => [['row' => 5, 'error_message' => 'Bad']]]);
        $this->getJson(self::PREFIX . "/categories/import/{$import->id}/download-errors")->assertStatus(403);
    }

    /** @test */
    public function admin_with_both_permissions_can_run_full_import_export_flow(): void
    {
        Queue::fake();
        Storage::fake('public');

        $user = $this->createUserWithPermissions(self::CATEGORY_IMPORT_EXPORT_PERMISSIONS, 'admin');
        Sanctum::actingAs($user);

        $this->getJson(self::PREFIX . '/categories/import/sample')->assertStatus(200);

        $importResponse = $this->postJson(self::PREFIX . '/categories/import', ['file' => $this->sampleFile()]);
        $importResponse->assertStatus(202);
        $importId = $importResponse->json('data.import_id');

        $this->getJson(self::PREFIX . "/categories/import/{$importId}")->assertStatus(200);

        $completed = $this->createImport([
            'type' => 'category',
            'status' => 'completed',
            'errors' => [['row' => 3, 'error_message' => 'Bad']]]);
        $this->getJson(self::PREFIX . "/categories/import/{$completed->id}/download-errors")->assertStatus(200);

        $exportResponse = $this->getJson(self::PREFIX . '/categories/export');
        $exportResponse->assertStatus(202);
        $exportId = $exportResponse->json('data.export_id');

        $this->getJson(self::PREFIX . "/categories/export/{$exportId}")->assertStatus(200);

        $exportDone = $this->createImport([
            'type' => 'category-export',
            'file_path' => 'exports/categories.xlsx',
            'file_name' => 'categories.xlsx',
            'status' => 'completed']);
        Storage::disk('public')->put('exports/categories.xlsx', 'x');
        $this->getJson(self::PREFIX . "/categories/export/{$exportDone->id}/download")->assertStatus(200);
    }

    /** @test */
    public function super_admin_can_access_all_category_import_export_endpoints(): void
    {
        $this->seed(PermissionSeeder::class);

        Queue::fake();
        Storage::fake('public');

        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'super-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin']);
        $user->assignRole(RoleEnum::SUPER_ADMIN);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($user);

        $this->getJson(self::PREFIX . '/categories/import/sample')->assertStatus(200);

        $importResponse = $this->postJson(self::PREFIX . '/categories/import', ['file' => $this->sampleFile()]);
        $importResponse->assertStatus(202);
        $importId = $importResponse->json('data.import_id');

        $this->getJson(self::PREFIX . "/categories/import/{$importId}")->assertStatus(200);

        $cancellable = $this->createImport(['created_by' => $user->id]);
        $this->postJson(self::PREFIX . "/categories/import/{$cancellable->id}/cancel")->assertStatus(200);

        $withErrors = $this->createImport(['created_by' => $user->id, 'status' => 'completed', 'errors' => [['row' => 2, 'error_message' => 'Bad']]]);
        $this->getJson(self::PREFIX . "/categories/import/{$withErrors->id}/download-errors")->assertStatus(200);

        $exportResponse = $this->getJson(self::PREFIX . '/categories/export');
        $exportResponse->assertStatus(202);
        $exportId = $exportResponse->json('data.export_id');

        $this->getJson(self::PREFIX . "/categories/export/{$exportId}")->assertStatus(200);

        $exportDone = $this->createImport([
            'type' => 'category-export',
            'file_path' => 'exports/categories.xlsx',
            'file_name' => 'categories.xlsx',
            'status' => 'completed',
            'created_by' => $user->id]);
        Storage::disk('public')->put('exports/categories.xlsx', 'x');
        $this->getJson(self::PREFIX . "/categories/export/{$exportDone->id}/download")->assertStatus(200);
    }

    /** @test */
    public function controllers_use_permission_enum_constants_not_raw_strings(): void
    {
        $controllers = [
            \Marvel\Http\Controllers\CategoryImportController::class,
            \Marvel\Http\Controllers\CategoryExportController::class];

        foreach ($controllers as $controller) {
            $reflection = new \ReflectionClass($controller);
            $source = file_get_contents($reflection->getFileName());

            $this->assertStringContainsString('Permission::', $source);
            $this->assertDoesNotMatchRegularExpression(
                "/middleware\('permission:'\s*\.\s*'[^']+'/",
                $source,
                "{$controller} must not hardcode raw permission strings in middleware"
            );
        }
    }
}
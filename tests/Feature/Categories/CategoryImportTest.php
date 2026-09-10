<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Marvel\Jobs\ImportCategoriesJob;
use Marvel\Services\Import\CategoryImportService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

class CategoryImportTest extends TestCase
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
            PermissionEnum::VIEW_CATEGORIES,
            PermissionEnum::CREATE_CATEGORY,
            PermissionEnum::UPDATE_CATEGORY,
            PermissionEnum::DELETE_CATEGORY,
            PermissionEnum::IMPORT_CATEGORY,
            PermissionEnum::EXPORT_CATEGORY];

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

    private function row(array $overrides = []): array
    {
        return array_merge([
            'name_en' => 'Category ' . uniqid(),
            'name_ar' => 'فئة',
            'details_en' => 'Details',
            'details_ar' => 'تفاصيل',
            'parent_name_en' => '',
            'status' => 1,
            'is_featured' => 0,
            'image_desktop_url' => '',
            'image_mobile_url' => ''], $overrides);
    }

    private function service(): CategoryImportService
    {
        return new CategoryImportService();
    }

    private function writeImportFile(array $rows): string
    {
        $headers = ['name_en','name_ar','details_en','details_ar','parent_name_en','status','is_featured','image_desktop_url','image_mobile_url'];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('categories');
        $sheet->fromArray($headers, null, 'A1');

        foreach ($rows as $index => $overrides) {
            $row = array_replace([
                'name_en' => 'Category ' . uniqid(),
                'name_ar' => 'فئة',
                'details_en' => '',
                'details_ar' => '',
                'parent_name_en' => '',
                'status' => 1,
                'is_featured' => 0,
                'image_desktop_url' => '',
                'image_mobile_url' => ''], $overrides);

            $sheet->fromArray($row, null, 'A' . ($index + 2));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        (new XlsxWriter($spreadsheet))->save($tmp);

        $path = 'imports/job-test-' . uniqid() . '.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmp));

        @unlink($tmp);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function makeJobImport(string $path, int $totalRows): Import
    {
        return Import::create([
            'type' => 'category',
            'file_path' => $path,
            'file_name' => basename($path),
            'status' => 'pending',
            'total_rows' => $totalRows,
            'created_by' => $this->adminUser->id]);
    }

    public function test_job_marks_full_success_import_as_completed(): void
    {
        Storage::fake('public');

        $path = $this->writeImportFile([
            ['name_en' => 'E2E Job Good One', 'name_ar' => 'جيد'],
            ['name_en' => 'E2E Job Good Two', 'name_ar' => 'جيد ٢']]);

        $import = $this->makeJobImport($path, 2);

        (new ImportCategoriesJob($import->id))->handle();

        $import->refresh();

        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->success_rows);
        $this->assertSame(0, $import->failed_rows);
    }

    public function test_job_marks_partial_import_as_completed_with_errors(): void
    {
        Storage::fake('public');

        $path = $this->writeImportFile([
            ['name_en' => 'E2E Job Good One', 'name_ar' => 'جيد'],
            ['name_en' => 'E2E Job Bad Status', 'name_ar' => 'سيئ', 'status' => 'maybe']]);

        $import = $this->makeJobImport($path, 2);

        (new ImportCategoriesJob($import->id))->handle();

        $import->refresh();

        $this->assertSame('completed_with_errors', $import->status);
        $this->assertSame(1, $import->success_rows);
        $this->assertSame(1, $import->failed_rows);
    }

    public function test_job_marks_all_failed_import_as_failed(): void
    {
        Storage::fake('public');

        $path = $this->writeImportFile([
            ['name_en' => 'E2E Job Bad Status', 'name_ar' => 'سيئ', 'status' => 'maybe'],
            ['name_en' => 'E2E Job Empty Name', 'name_ar' => '']]);

        $import = $this->makeJobImport($path, 2);

        (new ImportCategoriesJob($import->id))->handle();

        $import->refresh();

        $this->assertSame('failed', $import->status);
        $this->assertSame(0, $import->success_rows);
        $this->assertSame(2, $import->failed_rows);
    }    public function test_unauthenticated_user_cannot_import(): void
    {
        $response = $this->postJson(self::PREFIX . '/categories/import', []);

        $response->assertUnauthorized();
    }

    public function test_import_validates_file_required(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->postJson(self::PREFIX . '/categories/import', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file']);
    }

    public function test_import_validates_file_type(): void
    {
        Sanctum::actingAs($this->adminUser);

        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->postJson(self::PREFIX . '/categories/import', ['file' => $file]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file']);
    }

    public function test_import_dispatches_job_and_returns_202(): void
    {
        Queue::fake();
        Storage::fake('public');

        Sanctum::actingAs($this->adminUser);

        $file = UploadedFile::fake()->create('categories.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response = $this->postJson(self::PREFIX . '/categories/import', ['file' => $file]);

        $response->assertStatus(202);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['data' => ['import_id', 'status']]);

        $this->assertDatabaseHas('imports', [
            'id' => $response->json('data.import_id'),
            'type' => 'category-import',
            'status' => 'pending']);

        Queue::assertPushed(ImportCategoriesJob::class);
    }

    public function test_status_endpoint_uses_successful_rows_mapping(): void
    {
        Sanctum::actingAs($this->adminUser);

        $import = Import::create([
            'type' => 'category',
            'file_path' => 'imports/test.xlsx',
            'file_name' => 'test.xlsx',
            'status' => 'completed',
            'total_rows' => 10,
            'processed_rows' => 10,
            'success_rows' => 8,
            'failed_rows' => 2,
            'created_by' => $this->adminUser->id]);

        $response = $this->getJson(self::PREFIX . "/categories/import/{$import->id}");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'completed');
        $response->assertJsonPath('data.total_rows', 10);
        $response->assertJsonPath('data.successful_rows', 8);
        $response->assertJsonPath('data.failed_rows', 2);
        $response->assertJsonPath('data.error_count', 0);
        $response->assertJsonMissingPath('data.success_rows');
    }

    public function test_status_returns_404_for_nonexistent_import(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson(self::PREFIX . '/categories/import/99999');

        $response->assertNotFound();
    }

    public function test_service_creates_categories_with_deterministic_slug(): void
    {
        $service = $this->service();

        $service->processRows(new Collection([
            $this->row(['name_en' => 'Electronics', 'name_ar' => 'إلكترونيات'])]));

        $this->assertDatabaseHas('categories', ['slug' => 'electronics']);
        $this->assertEquals(1, $service->getSuccessCount());
        $this->assertEmpty($service->getFailedRows());

        $category = Category::where('slug', 'electronics')->first();
        $this->assertEquals('Electronics', $category->getTranslation('name', 'en'));
        $this->assertEquals('إلكترونيات', $category->getTranslation('name', 'ar'));
        $this->assertNull($category->parent_id);
        $this->assertEquals(1, $category->level);
    }

    public function test_service_creates_hierarchy_row_order_independent(): void
    {
        $service = $this->service();

        $service->processRows(new Collection([
            $this->row(['name_en' => 'iPhone', 'name_ar' => 'آيفون', 'parent_name_en' => 'Smartphones']),
            $this->row(['name_en' => 'Phones', 'name_ar' => 'هواتف', 'parent_name_en' => 'Electronics']),
            $this->row(['name_en' => 'Smartphones', 'name_ar' => 'هواتف ذكية', 'parent_name_en' => 'Phones']),
            $this->row(['name_en' => 'Electronics', 'name_ar' => 'إلكترونيات'])]));

        $this->assertEquals(4, $service->getSuccessCount());
        $this->assertEmpty($service->getFailedRows());

        $electronics = Category::where('slug', 'electronics')->first();
        $phones = Category::where('slug', 'phones')->first();
        $smartphones = Category::where('slug', 'smartphones')->first();
        $iphone = Category::where('slug', 'iphone')->first();

        $this->assertNotNull($electronics);
        $this->assertNotNull($phones);
        $this->assertNotNull($smartphones);
        $this->assertNotNull($iphone);

        $this->assertEquals($electronics->id, $phones->parent_id);
        $this->assertEquals($phones->id, $smartphones->parent_id);
        $this->assertEquals($smartphones->id, $iphone->parent_id);

        $this->assertEquals(1, $electronics->level);
        $this->assertEquals(2, $phones->level);
        $this->assertEquals(3, $smartphones->level);
        $this->assertEquals(4, $iphone->level);
    }

    public function test_service_updates_existing_category_on_reimport(): void
    {
        Category::create([
            'name' => ['en' => 'Electronics', 'ar' => 'إلكترونيات'],
            'slug' => 'electronics',
            'details' => 'Old details',
            'status' => 1,
            'is_featured' => 0]);

        $service = $this->service();

        $service->processRows(new Collection([
            $this->row([
                'name_en' => 'Electronics',
                'name_ar' => 'إلكترونيات محدثة',
                'details_en' => 'New details',
                'details_ar' => 'تفاصيل جديدة',
                'status' => 0,
                'is_featured' => 1])]));

        $this->assertEquals(1, $service->getSuccessCount());
        $this->assertEmpty($service->getFailedRows());

        $category = Category::where('slug', 'electronics')->first();
        $this->assertEquals('إلكترونيات محدثة', $category->getTranslation('name', 'ar'));
        $this->assertEquals('New details', $category->getTranslation('details', 'en'));
        $this->assertEquals('تفاصيل جديدة', $category->getTranslation('details', 'ar'));
        $this->assertEquals(0, (int) $category->status);
        $this->assertEquals(1, (int) $category->is_featured);
    }

    public function test_service_slug_conflict_is_row_error(): void
    {
        $existing = Category::create([
            'name' => ['en' => 'Electronics'],
            'slug' => 'electronics']);

        $service = $this->service();

        // Slug collision via punctuation variant, not case variant, keeps slug conflict under case-insensitive identity.
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Electronics!', 'name_ar' => 'إلكترونيات'])]));

        $this->assertEquals(0, $service->getSuccessCount());
        $this->assertCount(1, $service->getFailedRows());
        $this->assertStringContainsString('Slug', $service->getFailedRows()[0]['error_message']);

        $this->assertDatabaseCount('categories', 1);
        $this->assertSame($existing->id, Category::withTrashed()->first()->id);
    }

    public function test_service_missing_parent_is_row_error(): void
    {
        // Updated contract: missing parent is non-fatal, category persists as root
        $service = $this->service();

        $service->processRows(new Collection([
            $this->row(['name_en' => 'Orphan', 'parent_name_en' => 'Does Not Exist'])]));

        $this->assertEquals(1, $service->getSuccessCount());
        $this->assertCount(0, $service->getFailedRows());
        $cat = Category::where('slug', 'orphan')->first();
        $this->assertNotNull($cat);
        $this->assertNull($cat->parent_id);
    }

    public function test_service_self_parent_is_row_error_and_category_stays_root(): void
    {
        $service = $this->service();

        $service->processRows(new Collection([
            $this->row(['name_en' => 'Gadgets', 'parent_name_en' => 'Gadgets'])]));

        $this->assertEquals(0, $service->getSuccessCount());
        $this->assertCount(1, $service->getFailedRows());

        $category = Category::where('slug', 'gadgets')->first();
        $this->assertNotNull($category);
        $this->assertNull($category->parent_id);
        $this->assertEquals(1, $category->level);
    }

    public function test_service_cycle_assignment_is_row_error(): void
    {
        $electronics = Category::create(['name' => ['en' => 'Electronics'], 'slug' => 'electronics']);
        $phones = Category::create(['name' => ['en' => 'Phones'], 'slug' => 'phones', 'parent_id' => $electronics->id]);

        $service = $this->service();

        $service->processRows(new Collection([
            $this->row(['name_en' => 'Electronics', 'parent_name_en' => 'Phones'])]));

        $this->assertEquals(0, $service->getSuccessCount());
        $this->assertCount(1, $service->getFailedRows());

        $electronics->refresh();
        $phones->refresh();
        $this->assertNull($electronics->parent_id);
        $this->assertEquals($electronics->id, $phones->parent_id);
    }

    public function test_service_duplicate_name_in_file_is_row_error(): void
    {
        $service = $this->service();

        $service->processRows(new Collection([
            $this->row(['name_en' => 'Duplicated']),
            $this->row(['name_en' => 'Duplicated'])]));

        $this->assertEquals(1, $service->getSuccessCount());
        $this->assertCount(1, $service->getFailedRows());
        $this->assertSame('Duplicated', $service->getFailedRows()[0]['name_en']);
    }

    public function test_service_invalid_status_is_row_error(): void
    {
        $service = $this->service();

        $service->processRows(new Collection([
            $this->row(['name_en' => 'Bad Status', 'status' => 'invalid'])]));

        $this->assertEquals(0, $service->getSuccessCount());
        $this->assertCount(1, $service->getFailedRows());
        $this->assertDatabaseMissing('categories', ['slug' => 'bad-status']);
    }

    public function test_service_normalizes_boolean_like_values(): void
    {
        $service = $this->service();

        $service->processRows(new Collection([
            $this->row(['name_en' => 'Truthy', 'status' => 'yes', 'is_featured' => 'true'])]));

        $this->assertEquals(1, $service->getSuccessCount());

        $category = Category::where('slug', 'truthy')->first();
        $this->assertEquals(1, (int) $category->status);
        $this->assertEquals(1, (int) $category->is_featured);
    }

    public function test_service_empty_status_defaults_to_active_and_featured_to_zero(): void
    {
        $service = $this->service();

        $service->processRows(new Collection([
            $this->row(['name_en' => 'Defaults', 'status' => '', 'is_featured' => ''])]));

        $this->assertEquals(1, $service->getSuccessCount());

        $category = Category::where('slug', 'defaults')->first();
        $this->assertEquals(1, (int) $category->status);
        $this->assertEquals(0, (int) $category->is_featured);
    }

    public function test_service_rollback_deletes_only_created_categories(): void
    {
        $existing = Category::create(['name' => ['en' => 'Existing'], 'slug' => 'existing']);

        $service = $this->service();

        $service->processRows(new Collection([
            $this->row(['name_en' => 'Created One']),
            $this->row(['name_en' => 'Existing'])]));

        $this->assertEquals(2, $service->getSuccessCount());

        $service->rollbackCreatedData();

        $this->assertSoftDeleted(Category::where('slug', 'created-one')->first());
        $this->assertDatabaseHas('categories', ['id' => $existing->id, 'deleted_at' => null]);
    }

    public function test_download_sample_returns_file(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson(self::PREFIX . '/categories/import/sample');

        $response->assertOk();
    }

    public function test_cancel_import_returns_success(): void
    {
        Sanctum::actingAs($this->adminUser);

        $import = Import::create([
            'type' => 'category',
            'file_path' => 'imports/test.xlsx',
            'file_name' => 'test.xlsx',
            'status' => 'processing',
            'total_rows' => 100,
            'processed_rows' => 10,
            'success_rows' => 10,
            'failed_rows' => 0,
            'created_by' => $this->adminUser->id]);

        $response = $this->postJson(self::PREFIX . "/categories/import/{$import->id}/cancel");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('imports', [
            'id' => $import->id,
            'status' => 'cancelled']);
    }

    public function test_cannot_cancel_completed_import(): void
    {
        Sanctum::actingAs($this->adminUser);

        $import = Import::create([
            'type' => 'category',
            'file_path' => 'imports/test.xlsx',
            'file_name' => 'test.xlsx',
            'status' => 'completed',
            'total_rows' => 10,
            'processed_rows' => 10,
            'success_rows' => 10,
            'failed_rows' => 0,
            'created_by' => $this->adminUser->id]);

        $response = $this->postJson(self::PREFIX . "/categories/import/{$import->id}/cancel");

        $response->assertStatus(409);
    }
}
<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Marvel\Exports\CategoriesExport;
use Marvel\Jobs\ExportCategoriesJob;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CategoryExportTest extends TestCase
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
    }

    private function createSuperAdminUser(): User
    {
        $permissions = [
            PermissionEnum::EXPORT_CATEGORY,
            PermissionEnum::IMPORT_CATEGORY];

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

    public function test_unauthenticated_user_cannot_export(): void
    {
        $response = $this->getJson(self::PREFIX . '/categories/export');

        $response->assertUnauthorized();
    }

    public function test_export_dispatches_job_and_returns_202(): void
    {
        Queue::fake();

        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson(self::PREFIX . '/categories/export');

        $response->assertStatus(202);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['data' => ['export_id', 'status']]);

        $this->assertDatabaseHas('imports', [
            'id' => $response->json('data.export_id'),
            'type' => 'category-export',
            'status' => 'pending']);

        Queue::assertPushed(ExportCategoriesJob::class);
    }

    public function test_export_status_endpoint_returns_status(): void
    {
        Sanctum::actingAs($this->adminUser);

        $import = Import::create([
            'type' => 'category-export',
            'file_path' => '',
            'file_name' => '',
            'status' => 'completed',
            'total_rows' => 3,
            'processed_rows' => 3,
            'success_rows' => 3,
            'failed_rows' => 0,
            'created_by' => $this->adminUser->id]);

        $response = $this->getJson(self::PREFIX . "/categories/export/{$import->id}");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'completed');
        $response->assertJsonPath('data.total_rows', 3);
        $response->assertJsonPath('data.successful_rows', 3);
    }

    public function test_download_returns_409_when_not_ready(): void
    {
        Sanctum::actingAs($this->adminUser);

        $import = Import::create([
            'type' => 'category-export',
            'file_path' => '',
            'file_name' => '',
            'status' => 'processing',
            'total_rows' => 0,
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
            'created_by' => $this->adminUser->id]);

        $response = $this->getJson(self::PREFIX . "/categories/export/{$import->id}/download");

        $response->assertStatus(409);
    }

    public function test_download_returns_file_when_completed(): void
    {
        Storage::fake('public');

        Sanctum::actingAs($this->adminUser);

        $import = Import::create([
            'type' => 'category-export',
            'file_path' => 'categories-export-test.xlsx',
            'file_name' => 'categories-export-test.xlsx',
            'status' => 'completed',
            'total_rows' => 1,
            'processed_rows' => 1,
            'success_rows' => 1,
            'failed_rows' => 0,
            'created_by' => $this->adminUser->id]);

        Storage::disk('public')->put('categories-export-test.xlsx', 'fake-content');

        $response = $this->getJson(self::PREFIX . "/categories/export/{$import->id}/download");

        $response->assertOk();
        $response->assertHeader('content-disposition');
    }

    public function test_export_class_exposes_expected_headings(): void
    {
        $export = new CategoriesExport();

        $this->assertSame([
            'name_en',
            'name_ar',
            'details_en',
            'details_ar',
            'parent_name_en',
            'status',
            'is_featured',
            'image_desktop_url',
            'image_mobile_url'], $export->headings());
    }

    public function test_export_class_maps_parent_name_en(): void
    {
        $electronics = Category::create(['name' => ['en' => 'Electronics', 'ar' => 'إلكترونيات'], 'slug' => 'electronics']);
        $phones = Category::create([
            'name' => ['en' => 'Phones', 'ar' => 'هواتف'],
            'slug' => 'phones',
            'parent_id' => $electronics->id,
            'details' => ['en' => 'Mobile phones', 'ar' => 'هواتف محمولة']]);

        $export = new CategoriesExport();

        $this->assertEquals(2, $export->collection()->count());

        $phonesRow = $export->collection()->first(fn ($c) => (int) $c->id === (int) $phones->id);

        $this->assertSame('Electronics', $phonesRow->parent_name_en);

        $mapped = $export->map($phonesRow);

        $this->assertSame('Phones', $mapped[0]);
        $this->assertSame('هواتف', $mapped[1]);
        $this->assertSame('Mobile phones', $mapped[2]);
        $this->assertSame('هواتف محمولة', $mapped[3]);
        $this->assertSame('Electronics', $mapped[4]);
        $this->assertSame('1', $mapped[5]);
        $this->assertSame('0', $mapped[6]);
    }

    public function test_export_file_preserves_zero_status_and_featured_cells(): void
    {
        Storage::fake('public');

        $active = Category::create(['name' => ['en' => 'Active'], 'slug' => 'active', 'status' => 1, 'is_featured' => 0]);
        $inactive = Category::create(['name' => ['en' => 'Inactive'], 'slug' => 'inactive', 'status' => 0, 'is_featured' => 0]);
        $featuredInactive = Category::create(['name' => ['en' => 'Featured Inactive'], 'slug' => 'featured-inactive', 'status' => 0, 'is_featured' => 1]);

        $export = new CategoriesExport();
        $export->store('categories-roundtrip.xlsx', 'public');

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile(Storage::disk('public')->path('categories-roundtrip.xlsx'));
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load(Storage::disk('public')->path('categories-roundtrip.xlsx'));
        $rows = $spreadsheet->getActiveSheet()->toArray();
        $spreadsheet->disconnectWorksheets();

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row[0]] = $row;
        }

        $this->assertSame('1', $byName['Active'][5]);
        $this->assertSame('0', $byName['Active'][6]);
        $this->assertSame('0', $byName['Inactive'][5]);
        $this->assertSame('0', $byName['Inactive'][6]);
        $this->assertSame('0', $byName['Featured Inactive'][5]);
        $this->assertSame('1', $byName['Featured Inactive'][6]);
    }

    public function test_export_job_completes_and_writes_file(): void
    {
        Storage::fake('public');

        Category::create(['name' => ['en' => 'Electronics'], 'slug' => 'electronics']);

        $import = Import::create([
            'type' => 'category-export',
            'file_path' => '',
            'file_name' => '',
            'status' => 'pending',
            'total_rows' => 0,
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
            'created_by' => $this->adminUser->id]);

        $job = new ExportCategoriesJob($import->id);
        $job->handle();

        $import->refresh();

        $this->assertEquals('completed', $import->status);
        $this->assertEquals(1, $import->success_rows);
        $this->assertNotEmpty($import->file_path);
        Storage::disk('public')->assertExists($import->file_path);
    }
}
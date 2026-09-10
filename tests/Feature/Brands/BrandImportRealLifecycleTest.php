<?php

declare(strict_types=1);

namespace Tests\Feature\Brands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Enums\Permission as Perm;
use Marvel\Jobs\ExportBrandsJob;
use Marvel\Jobs\ImportBrandsJob;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BrandImportRealLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = storage_path('app/imports');
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    private const PREFIX = '/api/v1';
    private const GUARD = 'api';

    private function makeAdmin(array $perms = []): \Marvel\Database\Models\User
    {
        foreach ($perms as $p) {
            Permission::findOrCreate($p, self::GUARD);
        }
        Permission::findOrCreate(self::GUARD);
        foreach ([Perm::IMPORT_BRAND, Perm::EXPORT_BRAND] as $p) {
            Permission::findOrCreate($p, self::GUARD);
        }
        $role = Role::create(['name' => 'r_' . uniqid(), 'guard_name' => self::GUARD, 'display_name' => 'r']);
        foreach ($perms as $p) {
            $role->givePermissionTo($p);
        }
        $user = \Marvel\Database\Models\User::create([
            'name' => 'u_' . uniqid(),
            'email' => uniqid() . '@test.local',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin',
        ]);
        $user->assignRole($role);
        foreach ($perms as $p) {
            $user->givePermissionTo($p);
        }

        return $user;
    }

    private function createBrandWorkbook(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('brands');
        $sheet->fromArray(['name_en', 'name_ar', 'details_en', 'details_ar', 'status', 'image_desktop_url', 'image_mobile_url'], null, 'A1');
        $rowIdx = 2;
        foreach ($rows as $row) {
            $sheet->fromArray([
                $row['name_en'] ?? '',
                $row['name_ar'] ?? '',
                $row['details_en'] ?? '',
                $row['details_ar'] ?? '',
                $row['status'] ?? '',
                $row['image_desktop_url'] ?? '',
                $row['image_mobile_url'] ?? '',
            ], null, "A{$rowIdx}");
            $rowIdx++;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'brand');
        (new Xlsx($spreadsheet))->save($tmp);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $tmp;
    }

    public function test_real_brand_import_lifecycle(): void
    {
        Storage::fake('imports');
        Storage::fake('public');
        \Illuminate\Support\Facades\Queue::fake();

        $admin = $this->makeAdmin([Perm::IMPORT_BRAND]);
        Sanctum::actingAs($admin);

        $tmp = $this->createBrandWorkbook([
            ['name_en' => 'Real Brand One', 'name_ar' => 'علامة واحد', 'status' => 1],
            ['name_en' => 'Real Brand Two', 'name_ar' => 'علامة اثنين', 'status' => 0],
        ]);
        $uploaded = new UploadedFile($tmp, 'brands.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->postJson(self::PREFIX . '/brands/import', ['file' => $uploaded]);
        $response->assertStatus(202);
        $response->assertJsonPath('data.import_id', fn ($v) => $v !== null);
        $importId = $response->json('data.import_id');

        $import = Import::find($importId);
        $this->assertNotNull($import);
        $this->assertEquals(FileOperationType::BRAND_IMPORT, $import->operation_type);
        $this->assertEquals('pending', $import->status);
        $this->assertTrue(Storage::disk('imports')->exists($import->file_path), 'File must be on private imports disk');
        $this->assertFalse(Storage::disk('public')->exists($import->file_path), 'File must not be on public disk');

        // Run job synchronously (real lifecycle)
        (new ImportBrandsJob($importId))->handle();

        $import->refresh();
        $this->assertContains($import->status, ['completed', 'completed_with_errors']);
        $this->assertEquals(2, $import->success_rows);
        $this->assertEquals(0, $import->failed_rows);

        // Verify DB brands created
        $this->assertDatabaseHas('brands', ['slug' => 'real-brand-one']);
        $this->assertDatabaseHas('brands', ['slug' => 'real-brand-two']);

        // Status polling should succeed (tests type drift fix)
        $statusResponse = $this->getJson(self::PREFIX . "/brands/import/{$importId}");
        $statusResponse->assertOk();
        $statusResponse->assertJsonPath('data.status', $import->status);
        $statusResponse->assertJsonPath('data.successful_rows', 2);
        $statusResponse->assertJsonPath('data.id', $importId);

        // Source file should be cleaned up after success
        $this->assertFalse(Storage::disk('imports')->exists($import->file_path), 'Source file should be deleted after success');

        @unlink($tmp);
    }

    public function test_brand_import_invalid_file_type(): void
    {
        $admin = $this->makeAdmin([Perm::IMPORT_BRAND]);
        Sanctum::actingAs($admin);

        $file = UploadedFile::fake()->create('bad.pdf', 10, 'application/pdf');
        $response = $this->postJson(self::PREFIX . '/brands/import', ['file' => $file]);
        $response->assertStatus(422);
    }

    public function test_brand_import_requires_authentication(): void
    {
        $response = $this->postJson(self::PREFIX . '/brands/import');
        $response->assertStatus(401);
    }

    public function test_brand_import_wrong_owner_cannot_view_status(): void
    {
        $owner = $this->makeAdmin([Perm::IMPORT_BRAND]);
        $other = $this->makeAdmin([Perm::IMPORT_BRAND]);

        $import = Import::create([
            'type' => FileOperationType::BRAND_IMPORT,
            'file_path' => 'imports/test.xlsx',
            'file_name' => 'test.xlsx',
            'status' => 'completed',
            'total_rows' => 1,
            'success_rows' => 1,
            'failed_rows' => 0,
            'created_by' => $owner->id,
        ]);

        Sanctum::actingAs($other);
        $response = $this->getJson(self::PREFIX . "/brands/import/{$import->id}");
        // Current policy returns 403; ideal IDOR would be 404 but 403 is acceptable for now
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_brand_import_status_returns_404_for_wrong_operation_type(): void
    {
        $admin = $this->makeAdmin([Perm::IMPORT_BRAND, Perm::IMPORT_PRODUCT]);
        Sanctum::actingAs($admin);

        $productImport = Import::create([
            'type' => FileOperationType::PRODUCT_IMPORT,
            'file_path' => 'imports/test.xlsx',
            'file_name' => 'test.xlsx',
            'status' => 'completed',
            'total_rows' => 1,
            'success_rows' => 1,
            'failed_rows' => 0,
            'created_by' => $admin->id,
        ]);

        $response = $this->getJson(self::PREFIX . "/brands/import/{$productImport->id}");
        $response->assertStatus(404);
    }

    public function test_brand_import_cancellation_is_atomic(): void
    {
        $admin = $this->makeAdmin([Perm::IMPORT_BRAND]);
        Sanctum::actingAs($admin);

        $import = Import::create([
            'type' => FileOperationType::BRAND_IMPORT,
            'file_path' => 'imports/cancel-test.xlsx',
            'file_name' => 'test.xlsx',
            'status' => 'pending',
            'total_rows' => 0,
            'created_by' => $admin->id,
        ]);

        $response = $this->postJson(self::PREFIX . "/brands/import/{$import->id}/cancel");
        $response->assertOk();
        $response->assertJsonPath('data.status', 'cancelled');

        $import->refresh();
        $this->assertEquals('cancelled', $import->status);

        // Second cancel should be 409
        $response2 = $this->postJson(self::PREFIX . "/brands/import/{$import->id}/cancel");
        $response2->assertStatus(409);
    }

    public function test_brand_export_lifecycle(): void
    {
        Storage::fake('imports');

        // Seed a brand to export
        Brand::create([
            'name' => ['en' => 'Export Brand', 'ar' => 'تصدير'],
            'slug' => 'export-brand',
            'status' => 1,
        ]);

        $admin = $this->makeAdmin([Perm::EXPORT_BRAND]);
        Sanctum::actingAs($admin);

        // Start export via POST (canonical) and also verify GET still works
        $response = $this->postJson(self::PREFIX . '/brands/export');
        $response->assertStatus(202);
        $exportId = $response->json('data.export_id');
        $this->assertNotNull($exportId);

        $export = Import::find($exportId);
        $this->assertEquals(FileOperationType::BRAND_EXPORT, $export->operation_type);

        // Run job
        (new ExportBrandsJob($exportId))->handle();

        $export->refresh();
        $this->assertEquals('completed', $export->status);
        $this->assertNotEmpty($export->file_path);
        $this->assertTrue(Storage::disk('imports')->exists($export->file_path), 'Export file must be on private imports disk');
        $this->assertStringContainsString((string) $exportId, $export->file_path, 'Filename must be operation-specific');

        // Status polling
        $statusResponse = $this->getJson(self::PREFIX . "/brands/export/{$exportId}");
        $statusResponse->assertOk();
        $statusResponse->assertJsonPath('data.id', $exportId);
        $statusResponse->assertJsonPath('data.status', 'completed');

        // Download
        $downloadResponse = $this->getJson(self::PREFIX . "/brands/export/{$exportId}/download");
        // For binary download, we need to use get not getJson, but we check status
        $downloadResponse2 = $this->get(self::PREFIX . "/brands/export/{$exportId}/download");
        $downloadResponse2->assertOk();
        $this->assertEquals('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $downloadResponse2->headers->get('Content-Type'));
    }

    public function test_brand_export_get_still_works_for_compatibility(): void
    {
        Storage::fake('imports');
        $admin = $this->makeAdmin([Perm::EXPORT_BRAND]);
        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/brands/export');
        $response->assertStatus(202);
    }

    public function test_import_job_rejects_wrong_operation_type(): void
    {
        $admin = $this->makeAdmin([Perm::IMPORT_BRAND]);
        $import = Import::create([
            'type' => FileOperationType::PRODUCT_IMPORT,
            'file_path' => 'imports/wrong.xlsx',
            'file_name' => 'wrong.xlsx',
            'status' => 'pending',
            'total_rows' => 0,
            'created_by' => $admin->id,
        ]);

        (new ImportBrandsJob($import->id))->handle();

        $import->refresh();
        $this->assertEquals('failed', $import->status);
    }

    public function test_export_job_rejects_wrong_operation_type(): void
    {
        $admin = $this->makeAdmin([Perm::EXPORT_BRAND]);
        $import = Import::create([
            'type' => FileOperationType::BRAND_IMPORT,
            'file_path' => '',
            'file_name' => '',
            'status' => 'pending',
            'total_rows' => 0,
            'created_by' => $admin->id,
        ]);

        (new ExportBrandsJob($import->id))->handle();

        $import->refresh();
        $this->assertEquals('failed', $import->status);
    }

    public function test_idempotency_header_reuses_existing_import(): void
    {
        Storage::fake('imports');
        $admin = $this->makeAdmin([Perm::IMPORT_BRAND]);
        Sanctum::actingAs($admin);

        $tmp = $this->createBrandWorkbook([['name_en' => 'Idempotent Brand', 'name_ar' => 'تكرار', 'status' => 1]]);
        $uploaded = new UploadedFile($tmp, 'brands.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response1 = $this->postJson(self::PREFIX . '/brands/import', ['file' => $uploaded], ['Idempotency-Key' => 'test-key-123']);
        $response1->assertStatus(202);
        $id1 = $response1->json('data.import_id');

        // Second request with same key should reuse
        $tmp2 = $this->createBrandWorkbook([['name_en' => 'Idempotent Brand', 'name_ar' => 'تكرار', 'status' => 1]]);
        $uploaded2 = new UploadedFile($tmp2, 'brands.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $response2 = $this->postJson(self::PREFIX . '/brands/import', ['file' => $uploaded2], ['Idempotency-Key' => 'test-key-123']);
        $response2->assertStatus(202);
        $id2 = $response2->json('data.import_id');

        $this->assertEquals($id1, $id2, 'Same idempotency key should return same import_id');

        @unlink($tmp);
        @unlink($tmp2);
    }
}

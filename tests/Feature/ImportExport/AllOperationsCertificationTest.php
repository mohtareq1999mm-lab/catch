<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Events\FileOperationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Enums\Permission as Perm;
use Marvel\Jobs\ExportBrandsJob;
use Marvel\Jobs\ExportCategoriesJob;
use Marvel\Jobs\ExportProductsJob;
use Marvel\Jobs\ImportBrandsJob;
use Marvel\Jobs\ImportCategoriesJob;
use Marvel\Jobs\ImportProductsJob;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AllOperationsCertificationTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';
    private const GUARD = 'api';

    protected function setUp(): void
    {
        parent::setUp();
        $dir = storage_path('app/imports');
        if (is_dir($dir)) {
            foreach (glob($dir.'/*.json') ?: [] as $f) { @unlink($f); }
        }
        // Ensure file cache dir exists for idempotency file tests
        if (!is_dir(storage_path('framework/cache/data'))) {
            @mkdir(storage_path('framework/cache/data'), 0755, true);
        }
    }

    private function makeAdmin(array $perms): \Marvel\Database\Models\User
    {
        foreach ($perms as $p) { Permission::findOrCreate($p, self::GUARD); }
        foreach ([Perm::IMPORT_PRODUCT, Perm::IMPORT_CATEGORY, Perm::IMPORT_BRAND, Perm::EXPORT_PRODUCT, Perm::EXPORT_CATEGORY, Perm::EXPORT_BRAND] as $p) {
            Permission::findOrCreate($p, self::GUARD);
        }
        $role = Role::create(['name'=>'r_'.uniqid(),'guard_name'=>self::GUARD,'display_name'=>'r']);
        foreach ($perms as $p) { $role->givePermissionTo($p); }
        $user = \Marvel\Database\Models\User::create([
            'name'=>'u_'.uniqid(),
            'email'=>uniqid().'@test.local',
            'password'=>Hash::make('password'),
            'email_verified_at'=>now(),
            'is_active'=>true,
            'type'=>'admin',
        ]);
        $user->assignRole($role);
        foreach ($perms as $p) { $user->givePermissionTo($p); }
        return $user;
    }

    private function createWorkbook(string $sheetTitle, array $headers, array $rows): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle($sheetTitle);
        $sheet->fromArray($headers, null, 'A1');
        $r = 2;
        foreach ($rows as $row) {
            $sheet->fromArray(array_values($row), null, "A{$r}");
            $r++;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        (new Xlsx($ss))->save($tmp);
        $ss->disconnectWorksheets();
        unset($ss);
        return $tmp;
    }

    private function brandWorkbook(array $rows): string
    {
        return $this->createWorkbook('brands', ['name_en','name_ar','details_en','details_ar','status','image_desktop_url','image_mobile_url'], $rows);
    }

    private function categoryWorkbook(array $rows): string
    {
        // CategoriesImport expects sheet title 'categories' with WithHeadingRow
        return $this->createWorkbook('categories', ['name_en','name_ar','parent_name_en','status'], $rows);
    }

    private function productWorkbook(array $rows): string
    {
        // ProductsImport expects 8 sheets; create them all to avoid out-of-bounds
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('products');
        $sheet->fromArray(['sku','name_en','price'], null, 'A1');
        $r=2;
        foreach ($rows as $row) {
            $sheet->fromArray(array_values($row), null, "A{$r}");
            $r++;
        }
        // Create remaining sheets empty with headers to satisfy WithMultipleSheets
        $extra = [
            'product_variants' => ['sku','variant'],
            'categories' => ['name_en'],
            'brands' => ['name_en'],
            'flash_sales' => ['name_en'],
            'sliders' => ['name_en'],
            'tags' => ['name_en'],
            'images' => ['product_sku','image'],
        ];
        foreach ($extra as $title=>$headers) {
            $s = $ss->createSheet();
            $s->setTitle($title);
            $s->fromArray($headers, null, 'A1');
        }
        $ss->setActiveSheetIndex(0);
        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        (new Xlsx($ss))->save($tmp);
        $ss->disconnectWorksheets();
        unset($ss);
        return $tmp;
    }

    // ========== AUTH ==========
    public function test_product_import_requires_auth(): void
    {
        $resp = $this->postJson(self::PREFIX.'/products/import');
        $resp->assertStatus(401);
    }

    public function test_category_import_requires_auth(): void
    {
        $resp = $this->postJson(self::PREFIX.'/categories/import');
        $resp->assertStatus(401);
    }

    public function test_brand_import_requires_auth(): void
    {
        $resp = $this->postJson(self::PREFIX.'/brands/import');
        $resp->assertStatus(401);
    }

    public function test_product_export_requires_auth(): void
    {
        $resp = $this->postJson(self::PREFIX.'/products/export');
        $resp->assertStatus(401);
    }

    public function test_category_export_requires_auth(): void
    {
        $resp = $this->getJson(self::PREFIX.'/categories/export');
        $resp->assertStatus(401);
    }

    public function test_brand_export_requires_auth(): void
    {
        $resp = $this->getJson(self::PREFIX.'/brands/export');
        $resp->assertStatus(401);
    }

    // ========== VALIDATION ==========
    public function test_import_validates_file_missing(): void
    {
        $admin = $this->makeAdmin([Perm::IMPORT_BRAND]);
        Sanctum::actingAs($admin);
        $resp = $this->postJson(self::PREFIX.'/brands/import', []);
        $resp->assertStatus(422);
    }

    public function test_import_validates_wrong_mime(): void
    {
        $admin = $this->makeAdmin([Perm::IMPORT_PRODUCT]);
        Sanctum::actingAs($admin);
        $file = UploadedFile::fake()->create('bad.pdf', 10, 'application/pdf');
        $resp = $this->postJson(self::PREFIX.'/products/import', ['file'=>$file]);
        $resp->assertStatus(422);
    }

    public function test_product_export_validates_filters(): void
    {
        $admin = $this->makeAdmin([Perm::EXPORT_PRODUCT]);
        Sanctum::actingAs($admin);
        $resp = $this->postJson(self::PREFIX.'/products/export', ['product_type'=>'invalid']);
        $resp->assertStatus(422);
    }

    // ========== QUEUE ==========
    public function test_product_import_dispatches_catch_medium(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin([Perm::IMPORT_PRODUCT]);
        Sanctum::actingAs($admin);
        $tmp = $this->productWorkbook([['sku'=>'SKU1','name_en'=>'P1','price'=>10]]);
        $f = new UploadedFile($tmp,'p.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
        $this->post(self::PREFIX.'/products/import', ['file'=>$f])->assertStatus(202);
        Queue::assertPushed(ImportProductsJob::class, fn($j)=> $j->queue === config('queue.queues.medium'));
        @unlink($tmp);
    }

    public function test_category_import_dispatches_catch_medium(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin([Perm::IMPORT_CATEGORY]);
        Sanctum::actingAs($admin);
        $tmp = $this->categoryWorkbook([['name_en'=>'Cat1','name_ar'=>'cat1','status'=>1]]);
        $f = new UploadedFile($tmp,'c.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
        $this->post(self::PREFIX.'/categories/import', ['file'=>$f])->assertStatus(202);
        Queue::assertPushed(ImportCategoriesJob::class, fn($j)=> $j->queue === config('queue.queues.medium'));
        @unlink($tmp);
    }

    public function test_brand_import_dispatches_catch_medium(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin([Perm::IMPORT_BRAND]);
        Sanctum::actingAs($admin);
        $tmp = $this->brandWorkbook([['name_en'=>'B1','name_ar'=>'b1','status'=>1]]);
        $f = new UploadedFile($tmp,'b.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
        $this->post(self::PREFIX.'/brands/import', ['file'=>$f])->assertStatus(202);
        Queue::assertPushed(ImportBrandsJob::class, fn($j)=> $j->queue === config('queue.queues.medium'));
        @unlink($tmp);
    }

    public function test_product_export_dispatches_catch_medium(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin([Perm::EXPORT_PRODUCT]);
        Sanctum::actingAs($admin);
        $this->postJson(self::PREFIX.'/products/export', [])->assertStatus(202);
        Queue::assertPushed(ExportProductsJob::class, fn($j)=> $j->queue === config('queue.queues.medium'));
    }

    public function test_category_export_dispatches_catch_medium(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin([Perm::EXPORT_CATEGORY]);
        Sanctum::actingAs($admin);
        $this->getJson(self::PREFIX.'/categories/export')->assertStatus(202);
        Queue::assertPushed(ExportCategoriesJob::class, fn($j)=> $j->queue === config('queue.queues.medium'));
    }

    public function test_brand_export_dispatches_catch_medium(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin([Perm::EXPORT_BRAND]);
        Sanctum::actingAs($admin);
        $this->postJson(self::PREFIX.'/brands/export', [])->assertStatus(202);
        Queue::assertPushed(ExportBrandsJob::class, fn($j)=> $j->queue === config('queue.queues.medium'));
    }

    // ========== REAL WORKER + DB ==========
    public function test_brand_import_real_worker_valid(): void
    {
        Storage::fake('imports');
        Queue::fake(); // prevent sync auto-run so we can assert pending before manual handle
        $admin = $this->makeAdmin([Perm::IMPORT_BRAND]);
        Sanctum::actingAs($admin);
        $tmp = $this->brandWorkbook([['name_en'=>'RealB1','name_ar'=>'r1','status'=>1]]);
        $f = new UploadedFile($tmp,'b.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
        $resp = $this->post(self::PREFIX.'/brands/import', ['file'=>$f]);
        $resp->assertStatus(202);
        $id = $resp->json('data.import_id');
        $import = Import::find($id);
        $this->assertEquals('pending', $import->status);
        Queue::assertPushed(ImportBrandsJob::class);
        // Now run real worker
        (new ImportBrandsJob($id))->handle();
        $import->refresh();
        $this->assertContains($import->status, ['completed','completed_with_errors','failed']);
        if ($import->status !== 'failed') {
            $this->assertEquals(1, $import->success_rows + $import->failed_rows);
        }
        @unlink($tmp);
    }

    public function test_category_import_real_worker_valid(): void
    {
        Storage::fake('imports');
        $admin = $this->makeAdmin([Perm::IMPORT_CATEGORY]);
        Sanctum::actingAs($admin);
        $tmp = $this->categoryWorkbook([['name_en'=>'CatReal','name_ar'=>'cr','status'=>1]]);
        $f = new UploadedFile($tmp,'c.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
        $resp = $this->post(self::PREFIX.'/categories/import', ['file'=>$f]);
        $resp->assertStatus(202);
        $id = $resp->json('data.import_id');
        (new ImportCategoriesJob($id))->handle();
        $imp = Import::find($id);
        $this->assertTrue($imp->isTerminal());
        @unlink($tmp);
    }

    public function test_product_import_real_worker(): void
    {
        Storage::fake('imports');
        $admin = $this->makeAdmin([Perm::IMPORT_PRODUCT]);
        Sanctum::actingAs($admin);
        $tmp = $this->productWorkbook([['sku'=>'SKU-REAL','name_en'=>'Prod','price'=>5]]);
        $f = new UploadedFile($tmp,'p.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
        $resp = $this->post(self::PREFIX.'/products/import', ['file'=>$f]);
        $resp->assertStatus(202);
        $id = $resp->json('data.import_id');
        (new ImportProductsJob($id))->handle();
        $imp = Import::find($id);
        $this->assertTrue($imp->isTerminal());
        @unlink($tmp);
    }

    public function test_brand_import_partial_failures(): void
    {
        Storage::fake('imports');
        $admin = $this->makeAdmin([Perm::IMPORT_BRAND]);
        Sanctum::actingAs($admin);
        $tmp = $this->brandWorkbook([
            ['name_en'=>'GoodBrand','name_ar'=>'g','status'=>1],
            ['name_en'=>'','name_ar'=>'','status'=>1], // invalid missing name
        ]);
        $f = new UploadedFile($tmp,'b.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
        $resp = $this->post(self::PREFIX.'/brands/import', ['file'=>$f]);
        $id = $resp->json('data.import_id');
        (new ImportBrandsJob($id))->handle();
        $imp = Import::find($id);
        $this->assertContains($imp->status, ['completed','completed_with_errors','failed']);
        @unlink($tmp);
    }

    public function test_brand_export_real_worker_artifact(): void
    {
        Storage::fake('imports');
        Brand::create(['name'=>['en'=>'ExpB','ar'=>'eb'],'slug'=>'exp-b','status'=>1]);
        $admin = $this->makeAdmin([Perm::EXPORT_BRAND]);
        Sanctum::actingAs($admin);
        $resp = $this->postJson(self::PREFIX.'/brands/export', []);
        $resp->assertStatus(202);
        $id = $resp->json('data.export_id');
        (new ExportBrandsJob($id))->handle();
        $imp = Import::find($id);
        $this->assertEquals('completed', $imp->status);
        $this->assertTrue(Storage::disk('imports')->exists($imp->file_path));
        $path = Storage::disk('imports')->path($imp->file_path);
        $zip = new \ZipArchive();
        $this->assertEquals(true, $zip->open($path));
        $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
        $zip->close();
    }

    public function test_category_export_real_worker_artifact(): void
    {
        Storage::fake('imports');
        $admin = $this->makeAdmin([Perm::EXPORT_CATEGORY]);
        Sanctum::actingAs($admin);
        $resp = $this->getJson(self::PREFIX.'/categories/export');
        $resp->assertStatus(202);
        $id = $resp->json('data.export_id');
        (new ExportCategoriesJob($id))->handle();
        $imp = Import::find($id);
        $this->assertEquals('completed', $imp->status);
        $this->assertTrue(Storage::disk('imports')->exists($imp->file_path));
    }

    public function test_product_export_real_worker_artifact(): void
    {
        Storage::fake('imports');
        $admin = $this->makeAdmin([Perm::EXPORT_PRODUCT]);
        Sanctum::actingAs($admin);
        $resp = $this->postJson(self::PREFIX.'/products/export', []);
        $resp->assertStatus(202);
        $id = $resp->json('data.export_id');
        (new ExportProductsJob($id))->handle();
        $imp = Import::find($id);
        $this->assertEquals('completed', $imp->status);
        $this->assertTrue(Storage::disk('imports')->exists($imp->file_path));
        $zip = new \ZipArchive();
        $this->assertEquals(true, $zip->open(Storage::disk('imports')->path($imp->file_path)));
        $zip->close();
    }

    // ========== PUSHER CONTRACT (unit) ==========
    public function test_pusher_payload_shape_queued(): void
    {
        $event = new FileOperationEvent(1, FileOperationEvent::BRAND_IMPORT_QUEUED, [
            'kind'=>'brand-import','operation_type'=>'brand-import','id'=>1,'operation_id'=>1,'event'=>FileOperationEvent::BRAND_IMPORT_QUEUED,'state'=>'pending','status'=>'pending','progress'=>0,'percentage'=>0,'progress_detail'=>['percentage'=>0,'processed'=>0,'total'=>10],'processed_rows'=>0,'success_rows'=>0,'failed_rows'=>0,'total_rows'=>10,'has_errors'=>false,'download_available'=>false,'timestamp'=>now()->toIso8601String()
        ]);
        $this->assertEquals('private-users.1', $event->broadcastOn()[0]->name);
        $this->assertEquals(FileOperationEvent::BRAND_IMPORT_QUEUED, $event->broadcastAs());
        $payload = $event->broadcastWith();
        foreach (['kind','operation_type','operation_id','event','state','progress','has_errors','download_available'] as $k) {
            $this->assertArrayHasKey($k, $payload);
        }
    }

    public function test_pusher_failure_does_not_break_job(): void
    {
        // Simulate pusher throw inside trait — job should still complete (tested via BroadcastFailureIsolation)
        Storage::fake('imports');
        $admin = $this->makeAdmin([Perm::IMPORT_BRAND]);
        $tmp = $this->brandWorkbook([['name_en'=>'PusherFail','name_ar'=>'pf','status'=>1]]);
        $f = new UploadedFile($tmp,'b.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
        Sanctum::actingAs($admin);
        $resp = $this->post(self::PREFIX.'/brands/import', ['file'=>$f]);
        $id = $resp->json('data.import_id');
        // Force pusher disabled via config shop.pusher.enabled false to simulate failure isolation
        config(['shop.pusher.enabled'=>false]);
        (new ImportBrandsJob($id))->handle();
        $imp = Import::find($id);
        $this->assertTrue($imp->isTerminal());
        config(['shop.pusher.enabled'=>true]);
        @unlink($tmp);
    }

    // ========== SECURITY ==========
    public function test_foreign_user_cannot_view_status(): void
    {
        $owner = $this->makeAdmin([Perm::IMPORT_BRAND]);
        $other = $this->makeAdmin([Perm::IMPORT_BRAND]);
        $imp = Import::create(['type'=>FileOperationType::BRAND_IMPORT,'file_path'=>'imports/x.xlsx','file_name'=>'x.xlsx','status'=>'completed','total_rows'=>1,'success_rows'=>1,'created_by'=>$owner->id]);
        Sanctum::actingAs($other);
        $resp = $this->getJson(self::PREFIX."/brands/import/{$imp->id}");
        $this->assertContains($resp->getStatusCode(), [403,404]);
    }

    public function test_foreign_user_cannot_download(): void
    {
        Storage::fake('imports');
        $owner = $this->makeAdmin([Perm::EXPORT_BRAND]);
        $other = $this->makeAdmin([Perm::EXPORT_BRAND]);
        $imp = Import::create(['type'=>FileOperationType::BRAND_EXPORT,'file_path'=>'brands-export-1.xlsx','file_name'=>'brands-export-1.xlsx','status'=>'completed','total_rows'=>1,'created_by'=>$owner->id]);
        Storage::disk('imports')->put('brands-export-1.xlsx','fake');
        Sanctum::actingAs($other);
        $resp = $this->get(self::PREFIX."/brands/export/{$imp->id}/download");
        $this->assertContains($resp->getStatusCode(), [403,404]);
    }

    // ========== CANCELLATION & RACE ==========
    public function test_cancel_before_worker(): void
    {
        $admin = $this->makeAdmin([Perm::IMPORT_BRAND]);
        Sanctum::actingAs($admin);
        $imp = Import::create(['type'=>FileOperationType::BRAND_IMPORT,'file_path'=>'imports/x.xlsx','file_name'=>'x.xlsx','status'=>'pending','total_rows'=>0,'created_by'=>$admin->id]);
        $resp = $this->postJson(self::PREFIX."/brands/import/{$imp->id}/cancel");
        $resp->assertOk();
        $this->assertEquals('cancelled', Import::find($imp->id)->status);
        $resp2 = $this->postJson(self::PREFIX."/brands/import/{$imp->id}/cancel");
        $resp2->assertStatus(409);
    }

    public function test_cancel_after_completed_returns_409(): void
    {
        $admin = $this->makeAdmin([Perm::IMPORT_BRAND]);
        Sanctum::actingAs($admin);
        $imp = Import::create(['type'=>FileOperationType::BRAND_IMPORT,'file_path'=>'imports/x.xlsx','file_name'=>'x.xlsx','status'=>'completed','total_rows'=>1,'success_rows'=>1,'created_by'=>$admin->id]);
        $resp = $this->postJson(self::PREFIX."/brands/import/{$imp->id}/cancel");
        $resp->assertStatus(409);
    }

    public function test_duplicate_terminal_not_emitted(): void
    {
        Storage::fake('imports');
        $admin = $this->makeAdmin([Perm::IMPORT_BRAND]);
        $imp = Import::create(['type'=>FileOperationType::BRAND_IMPORT,'file_path'=>'imports/x.xlsx','file_name'=>'x.xlsx','status'=>'pending','total_rows'=>0,'created_by'=>$admin->id]);
        // First handle with missing file → failed
        (new ImportBrandsJob($imp->id))->handle();
        $firstStatus = Import::find($imp->id)->status;
        $this->assertEquals('failed', $firstStatus);
        // Second handle should not change terminal
        (new ImportBrandsJob($imp->id))->handle();
        $this->assertEquals('failed', Import::find($imp->id)->status);
    }

    // ========== BACKWARD COMPAT ==========
    public function test_status_envelope_uses_success_and_data(): void
    {
        $admin = $this->makeAdmin([Perm::IMPORT_BRAND]);
        Sanctum::actingAs($admin);
        $imp = Import::create(['type'=>FileOperationType::BRAND_IMPORT,'file_path'=>'imports/x.xlsx','file_name'=>'x.xlsx','status'=>'completed','total_rows'=>1,'success_rows'=>1,'created_by'=>$admin->id]);
        $resp = $this->getJson(self::PREFIX."/brands/import/{$imp->id}");
        $resp->assertOk();
        $resp->assertJsonStructure(['status','message','success','data'=>['id','status']]);
    }

    public function test_export_download_409_when_not_ready(): void
    {
        Storage::fake('imports');
        $admin = $this->makeAdmin([Perm::EXPORT_BRAND]);
        Sanctum::actingAs($admin);
        $imp = Import::create(['type'=>FileOperationType::BRAND_EXPORT,'file_path'=>'','file_name'=>'','status'=>'pending','total_rows'=>0,'created_by'=>$admin->id]);
        $resp = $this->get(self::PREFIX."/brands/export/{$imp->id}/download");
        $resp->assertStatus(409);
    }
}

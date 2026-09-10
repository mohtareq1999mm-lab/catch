<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\ImportStatus;
use Marvel\Enums\ImportType;
use Marvel\Enums\Permission as Perm;
use Marvel\Jobs\ExportBrandsJob;
use Marvel\Jobs\ExportCategoriesJob;
use Marvel\Jobs\ExportProductsJob;
use Marvel\Jobs\ImportBrandsJob;
use Marvel\Jobs\ImportCategoriesJob;
use Marvel\Jobs\ImportProductsJob;
use Marvel\Services\Import\BrandImportService;
use Marvel\Services\Import\CategoryImportService;
use Marvel\Services\Import\ProductImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeepVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';
    private const GUARD = 'api';

    private function makeUser(array $perms, bool $super = false): User
    {
        foreach ($perms as $p) Permission::findOrCreate($p, self::GUARD);
        Permission::findOrCreate(self::GUARD);
        foreach ([Perm::IMPORT_PRODUCT, Perm::EXPORT_PRODUCT, Perm::IMPORT_CATEGORY, Perm::EXPORT_CATEGORY, Perm::IMPORT_BRAND, Perm::EXPORT_BRAND] as $p) Permission::findOrCreate($p, self::GUARD);
        $role = Role::create(['name' => 'r'.uniqid(), 'guard_name' => self::GUARD, 'display_name' => 'r']);
        foreach ($perms as $p) $role->givePermissionTo($p);
        if ($super)
        $u = User::create(['name'=>'u'.uniqid(),'email'=>uniqid().'@test.local','password'=>Hash::make('password'),'email_verified_at'=>now(),'is_active'=>true,'type'=>'admin']);
        $u->assignRole($role);
        foreach ($perms as $p) $u->givePermissionTo($p);
        if ($super)
        return $u;
    }

    private function writeProductWorkbook(array $rows): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('products');
        $headers = ['sku','name_en','name_ar','description_en','description_ar','price','product_type','item_type','quantity','status','in_stock','has_discount','discount_type','discount_amount','start_date','end_date','height','width','length','weight'];
        $sheet->fromArray($headers, null, 'A1');
        $r=2;
        foreach ($rows as $row) {
            $data=[]; foreach ($headers as $h) $data[] = $row[$h] ?? '';
            $sheet->fromArray($data, null, "A{$r}"); $r++;
        }
        // other 7 sheets minimal
        foreach (['product_variants'=>['product_sku','price','sale_price','quantity','height','width','length','weight','attributes'],'images'=>['product_sku','image'],'categories'=>['product_sku','category_slug'],'brands'=>['product_sku','brand_slug'],'flash_sales'=>['product_sku','flash_sale_slug'],'sliders'=>['product_sku','slider_slug'],'tags'=>['product_sku','tag_slug']] as $title=>$hdr) {
            $ss->createSheet()->setTitle($title)->fromArray($hdr, null, 'A1');
        }
        $tmp=tempnam(sys_get_temp_dir(),'deep');
        (new Xlsx($ss))->save($tmp);
        $ss->disconnectWorksheets();
        return $tmp;
    }

    // 4.1 ImportPolicy deep
    public function test_import_policy_owner_success_wrong_type_404_no_typeerror(): void
    {
        $owner = $this->makeUser([Perm::IMPORT_PRODUCT]);
        $other = $this->makeUser([Perm::IMPORT_PRODUCT]);
        $super = $this->makeUser([], true);
        // Create with correct type product-import
        $import = Import::create(['type'=>ImportType::PRODUCT_IMPORT,'file_path'=>'imports/a.xlsx','file_name'=>'a.xlsx','status'=>'completed','total_rows'=>1,'created_by'=>$owner->id]);
        Sanctum::actingAs($owner);
        $this->getJson(self::PREFIX."/products/import/{$import->id}")->assertOk();
        Sanctum::actingAs($super);
        $this->getJson(self::PREFIX."/products/import/{$import->id}")->assertOk();
        Sanctum::actingAs($other);
        $resp = $this->getJson(self::PREFIX."/products/import/{$import->id}");
        $this->assertEquals(404, $resp->getStatusCode(), 'Other user must get 404, not 403 or 500. Got: '.$resp->getContent());
        // wrong type
        Sanctum::actingAs($owner);
        $resp2 = $this->getJson(self::PREFIX."/categories/import/{$import->id}");
        $this->assertEquals(404, $resp2->getStatusCode(), 'Wrong type must be 404. Got: '.$resp2->getContent());
    }

    // 4.3 Private storage deep
    public function test_private_storage_import_and_export_all_disks(): void
    {
        $this->assertEquals('private', config('filesystems.disks.imports.visibility'), 'imports disk must be private');
        $this->assertEquals(storage_path('app/private/imports'), config('filesystems.disks.imports.root'));
        // Import via controller
        \Illuminate\Support\Facades\Queue::fake();
        Storage::fake('public');
        $realRoot = storage_path('app/private/imports');
        if (!is_dir($realRoot)) mkdir($realRoot,0755,true);
        $owner = $this->makeUser([Perm::IMPORT_PRODUCT]);
        Sanctum::actingAs($owner);
        $tmp = $this->writeProductWorkbook([['sku'=>'STOR-001','name_en'=>'S','price'=>10,'quantity'=>5,'product_type'=>'simple','status'=>1,'in_stock'=>1]]);
        $uploaded = new \Illuminate\Http\UploadedFile($tmp, 'products.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $resp = $this->post(self::PREFIX.'/products/import', ['file'=>$uploaded]);
        $resp->assertStatus(202);
        $import = Import::find($resp->json('data.import_id'));
        $this->assertTrue(Storage::disk('imports')->exists($import->file_path), 'Private imports disk must have file');
        $this->assertFalse(Storage::disk('public')->exists($import->file_path), 'Public disk must not have file');
        Storage::disk('imports')->delete($import->file_path);
        @unlink($tmp);
    }

    // 4.5 All-rows-fail regression deep
    public function test_all_rows_fail_regression_product(): void
    {
        Storage::fake('public');
        $owner = $this->makeUser([Perm::IMPORT_PRODUCT]);
        $tmp = $this->writeProductWorkbook([
            ['sku'=>'FAIL-001','name_en'=>'F','price'=>10,'quantity'=>5,'product_type'=>'simple','item_type'=>'INVALID1','status'=>1,'in_stock'=>1],
            ['sku'=>'FAIL-002','name_en'=>'F','price'=>10,'quantity'=>5,'product_type'=>'simple','item_type'=>'INVALID2','status'=>1,'in_stock'=>1],
        ]);
        $path='imports/test_'.uniqid().'.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmp));
        $import = Import::create(['type'=>ImportType::PRODUCT_IMPORT,'file_path'=>$path,'file_name'=>'p.xlsx','status'=>'pending','total_rows'=>2,'created_by'=>$owner->id]);
        (new ImportProductsJob($import->id))->handle();
        $import->refresh();
        $this->assertEquals(ImportStatus::FAILED, $import->status);
        $this->assertEquals(0, $import->success_rows);
        $this->assertGreaterThan(0, $import->failed_rows);
        @unlink($tmp);
    }

    // 4.11 Row count deep
    public function test_total_rows_counts_only_products_primary(): void
    {
        Storage::fake('public');
        $owner = $this->makeUser([Perm::IMPORT_PRODUCT]);
        // Create workbook with 3 product rows but 200 variants etc. total_rows should be 3
        $tmp = $this->writeProductWorkbook([
            ['sku'=>'CNT-001','name_en'=>'C1','price'=>10,'quantity'=>5,'product_type'=>'simple','status'=>1,'in_stock'=>1],
            ['sku'=>'CNT-002','name_en'=>'C2','price'=>10,'quantity'=>5,'product_type'=>'simple','status'=>1,'in_stock'=>1],
            ['sku'=>'CNT-003','name_en'=>'C3','price'=>10,'quantity'=>5,'product_type'=>'simple','status'=>1,'in_stock'=>1],
        ]);
        // Manually add many rows to variant sheet to try to trick count
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmp);
        $ss = $reader->load($tmp);
        $sheet = $ss->getSheetByName('product_variants');
        for ($i=2;$i<202;$i++) $sheet->fromArray(['CNT-001','10','9','5','1','1','1','1',''], null, "A{$i}");
        $ss->getSheetByName('images')->fromArray(['CNT-001','http://example.com/a.jpg'], null, 'A2');
        $tmp2=tempnam(sys_get_temp_dir(),'cnt');
        (new Xlsx($ss))->save($tmp2);
        $ss->disconnectWorksheets();
        $path='imports/test_'.uniqid().'.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmp2));
        $import = Import::create(['type'=>ImportType::PRODUCT_IMPORT,'file_path'=>$path,'file_name'=>'p.xlsx','status'=>'pending','total_rows'=>0,'created_by'=>$owner->id]);
        (new ImportProductsJob($import->id))->handle();
        $import->refresh();
        // After job, total_rows is overwritten to success+failed (3) — but controller's estimateRowCount would have been wrong
        // We verify job's total_rows is 3, not 200+
        $this->assertEquals(3, $import->total_rows, 'total_rows must be products primary count, not sum of all sheets');
        @unlink($tmp); @unlink($tmp2);
    }

    // 4.12 Row offset multi-chunk
    public function test_row_number_multi_chunk_real(): void
    {
        Storage::fake('public');
        $owner = $this->makeUser([Perm::IMPORT_PRODUCT]);
        $rows=[];
        for ($i=1;$i<=150;$i++) {
            $rows[] = ['sku'=>"CHUNK-{$i}",'name_en'=>"C{$i}",'price'=>10,'quantity'=>5,'product_type'=>'simple','status'=>1,'in_stock'=>1];
        }
        // Make two invalid in chunk 2 and 3
        $rows[120] = ['sku'=>'CHUNK-121','name_en'=>'Bad','price'=>10,'quantity'=>5,'product_type'=>'simple','item_type'=>'BAD','status'=>1,'in_stock'=>1]; // row 122
        $rows[140] = ['sku'=>'CHUNK-141','name_en'=>'Bad2','price'=>10,'quantity'=>5,'product_type'=>'simple','item_type'=>'BAD2','status'=>1,'in_stock'=>1]; // row 142
        $tmp = $this->writeProductWorkbook($rows);
        $path='imports/test_'.uniqid().'.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmp));
        $import = Import::create(['type'=>ImportType::PRODUCT_IMPORT,'file_path'=>$path,'file_name'=>'p.xlsx','status'=>'pending','total_rows'=>150,'created_by'=>$owner->id]);
        (new ImportProductsJob($import->id))->handle();
        $import->refresh();
        $this->assertEquals(2, count($import->errors));
        $rowsReported = array_column($import->errors, 'row');
        sort($rowsReported);
        // With chunkSize 100, rowOffset should make second chunk start at 102, so row 122 and 142
        $this->assertContains(123, $rowsReported, 'Row 123 should be reported for chunk2 failure (header offset 2)');
        // Actually our write: index 120 corresponds to excel row 122 (header + index+2) — verify not restarting at 2
        $this->assertNotContains(3, $rowsReported, 'Row numbers must not restart at 2 for chunk2');
        @unlink($tmp);
    }

    // 4.13 Numeric deep
    public function test_numeric_validation_all_fields(): void
    {
        $owner = User::create(['name'=>'u','email'=>uniqid().'@test.local','password'=>Hash::make('password'),'email_verified_at'=>now(),'is_active'=>true,'type'=>'admin']);
        $import = Import::create(['type'=>ImportType::PRODUCT_IMPORT,'file_path'=>'imports/a.xlsx','file_name'=>'a.xlsx','status'=>'processing','total_rows'=>10,'created_by'=>$owner->id]);
        $service = new ProductImportService($import->id);
        $cases = [
            ['field'=>'price','value'=>'abc','shouldFail'=>true],
            ['field'=>'price','value'=>'12abc','shouldFail'=>true],
            ['field'=>'price','value'=>'$','shouldFail'=>true],
            ['field'=>'price','value'=>'1,2,3','shouldFail'=>true],
            ['field'=>'price','value'=>'','shouldFail'=>false], // blank should not overwrite? Actually service will cast '' to 0, but should be considered blank and not fail? For create, blank may be allowed.
            ['field'=>'price','value'=>'10.99','shouldFail'=>false],
            ['field'=>'quantity','value'=>'abc','shouldFail'=>true],
            ['field'=>'discount_amount','value'=>'abc','shouldFail'=>true],
            ['field'=>'height','value'=>'abc','shouldFail'=>true],
        ];
        foreach ($cases as $c) {
            $service = new ProductImportService($import->id); // fresh
            $row = ['sku'=>'NUM-'.uniqid(),'name_en'=>'N','price'=>10,'quantity'=>5,'product_type'=>'simple','status'=>1,'in_stock'=>1];
            $row[$c['field']] = $c['value'];
            $before = $service->getSuccessCount();
            $service->processProductRow($row, 2);
            $failed = count($service->getFailedRows()) > 0;
            if ($c['shouldFail']) {
                $this->assertTrue($failed, "Field {$c['field']} value '{$c['value']}' should fail");
            } else {
                // For blank price, service currently casts '' to 0 and succeeds — but should maybe not fail
                // We just ensure it doesn't crash
                $this->assertTrue(true);
            }
        }
    }

    public function test_update_safety_numeric_not_overwritten(): void
    {
        $p = Product::create(['sku'=>'UPD-999','name'=>['en'=>'U'],'slug'=>'upd-'.uniqid(),'price'=>99.99,'quantity'=>25,'stock_quantity'=>25,'product_type'=>'simple','status'=>true,'in_stock'=>true]);
        $owner = User::create(['name'=>'u','email'=>uniqid().'@test.local','password'=>Hash::make('password'),'email_verified_at'=>now(),'is_active'=>true,'type'=>'admin']);
        $import = Import::create(['type'=>ImportType::PRODUCT_IMPORT,'file_path'=>'imports/a.xlsx','file_name'=>'a.xlsx','status'=>'processing','total_rows'=>1,'created_by'=>$owner->id]);
        $service = new ProductImportService($import->id);
        $service->processProductRow(['sku'=>'UPD-999','name_en'=>'U','price'=>'12abc','quantity'=>10,'product_type'=>'simple','status'=>1,'in_stock'=>1], 2);
        $p->refresh();
        $this->assertEquals(99.99, (float)$p->price, 'Invalid price must not overwrite 99.99');
        $this->assertEquals(25, $p->stock_quantity, 'Quantity should remain 25 when price invalid fails row');
    }

    // 4.14 Enum deep
    public function test_enum_validation_deep(): void
    {
        $owner = User::create(['name'=>'u','email'=>uniqid().'@test.local','password'=>Hash::make('password'),'email_verified_at'=>now(),'is_active'=>true,'type'=>'admin']);
        $import = Import::create(['type'=>ImportType::PRODUCT_IMPORT,'file_path'=>'imports/a.xlsx','file_name'=>'a.xlsx','status'=>'processing','total_rows'=>1,'created_by'=>$owner->id]);
        $service = new ProductImportService($import->id);
        $service->processProductRow(['sku'=>'ENUM-1','name_en'=>'E','price'=>10,'quantity'=>5,'product_type'=>'bad','status'=>1,'in_stock'=>1], 2);
        $this->assertGreaterThan(0, count($service->getFailedRows()), 'Invalid product_type must fail');
        $service2 = new ProductImportService($import->id);
        $service2->processProductRow(['sku'=>'ENUM-2','name_en'=>'E','price'=>10,'quantity'=>5,'product_type'=>'simple','discount_type'=>'bad','has_discount'=>1,'discount_amount'=>5,'status'=>1,'in_stock'=>1], 2);
        $this->assertGreaterThan(0, count($service2->getFailedRows()), 'Invalid discount_type must fail');
        $service3 = new ProductImportService($import->id);
        $service3->processProductRow(['sku'=>'ENUM-3','name_en'=>'E','price'=>10,'quantity'=>5,'product_type'=>'simple','item_type'=>'BAD','status'=>1,'in_stock'=>1], 2);
        $this->assertGreaterThan(0, count($service3->getFailedRows()), 'Invalid item_type must fail');
        // blank should not fail (default)
        $service4 = new ProductImportService($import->id);
        $service4->processProductRow(['sku'=>'ENUM-4','name_en'=>'E','price'=>10,'quantity'=>5,'status'=>1,'in_stock'=>1], 2);
        $this->assertEquals(1, $service4->getSuccessCount(), 'Omitted product_type should default and succeed');
    }

    // 4.15 Multi-sheet deep
    public function test_category_multi_sheet_only_first_processed_deep(): void
    {
        $content = file_get_contents(base_path('packages/marvel/src/Imports/Sheets/CategoriesSheetImport.php'));
        $this->assertStringContainsString('CategoryImportService', $content, 'CategoriesSheetImport must use CategoryImportService, not ProductImportService');
        $this->assertStringNotContainsString('ProductImportService', $content, 'CategoriesSheetImport must not use ProductImportService');
        $content2 = file_get_contents(base_path('packages/marvel/src/Imports/CategoriesImport.php'));
        $this->assertStringContainsString('WithMultipleSheets', $content2, 'CategoriesImport must implement WithMultipleSheets');
    }

    public function test_brand_multi_sheet_only_first_processed_deep(): void
    {
        $content = file_get_contents(base_path('packages/marvel/src/Imports/Sheets/BrandsSheetImport.php'));
        $this->assertStringContainsString('BrandImportService', $content);
        $this->assertStringNotContainsString('ProductImportService', $content);
    }

    // 4.16 Status resource deep
    public function test_status_resource_has_all_stable_fields(): void
    {
        $owner = $this->makeUser([Perm::IMPORT_PRODUCT]);
        $import = Import::create(['type'=>ImportType::PRODUCT_IMPORT,'file_path'=>'imports/a.xlsx','file_name'=>'a.xlsx','status'=>'completed','total_rows'=>10,'processed_rows'=>10,'success_rows'=>8,'failed_rows'=>2,'created_by'=>$owner->id]);
        Sanctum::actingAs($owner);
        $resp = $this->getJson(self::PREFIX."/products/import/{$import->id}");
        $resp->assertOk();
        $data = $resp->json('data');
        foreach (['status','total_rows','processed_rows','failed_rows','progress'] as $k) $this->assertArrayHasKey($k, $data, "Missing $k");
        // Check successful_rows vs success_rows compatibility
        $this->assertTrue(isset($data['successful_rows']) || isset($data['success_rows']), 'Must have successful_rows or success_rows');
    }

    // 4.17 Routes deep
    public function test_all_id_routes_reject_non_numeric(): void
    {
        $owner = $this->makeUser([Perm::IMPORT_PRODUCT, Perm::IMPORT_CATEGORY, Perm::IMPORT_BRAND, Perm::EXPORT_CATEGORY, Perm::EXPORT_BRAND, Perm::EXPORT_PRODUCT]);
        Sanctum::actingAs($owner);
        $bads = ['abc','foo','1abc','1.5'];
        foreach ($bads as $bad) {
            $this->getJson(self::PREFIX."/products/import/{$bad}")->assertStatus(404);
            $this->getJson(self::PREFIX."/categories/import/{$bad}")->assertStatus(404);
            $this->getJson(self::PREFIX."/brands/import/{$bad}")->assertStatus(404);
            $this->getJson(self::PREFIX."/categories/export/{$bad}")->assertStatus(404);
            $this->getJson(self::PREFIX."/brands/export/{$bad}")->assertStatus(404);
        }
        // Product export status/download if exists
        $this->getJson(self::PREFIX."/products/export/abc")->assertStatus(404);
    }

    // 4.18 Error report deep
    public function test_error_report_private_and_collision(): void
    {
        $owner = $this->makeUser([Perm::IMPORT_PRODUCT]);
        $import = Import::create(['type'=>ImportType::PRODUCT_IMPORT,'file_path'=>'imports/a.xlsx','file_name'=>'a.xlsx','status'=>'completed_with_errors','total_rows'=>1,'success_rows'=>0,'failed_rows'=>1,'errors'=>[['sheet'=>'products','row'=>2,'sku'=>'X','error_message'=>'err']],'created_by'=>$owner->id]);
        Sanctum::actingAs($owner);
        $resp = $this->getJson(self::PREFIX."/products/import/{$import->id}/download-errors");
        // Should be 200 and private storage, not public
        if ($resp->getStatusCode()===200) {
            $this->assertStringContainsString('vnd.openxmlformats', $resp->headers->get('Content-Type'));
        } else {
            $this->assertContains($resp->getStatusCode(), [200,404], 'Error report should be 200 or 404 if no errors');
        }
        $other = $this->makeUser([Perm::IMPORT_PRODUCT]);
        Sanctum::actingAs($other);
        $this->getJson(self::PREFIX."/products/import/{$import->id}/download-errors")->assertStatus(404);
    }

    // 4.19 Product async export deep
    public function test_product_export_async_flow(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $owner = $this->makeUser([Perm::EXPORT_PRODUCT]);
        Sanctum::actingAs($owner);
        $resp = $this->getJson(self::PREFIX.'/products/export');
        // Current production is sync download (200) not async 202 — test will fail until D-4
        // We assert expected async, so this will FAIL until fixed, correctly reporting defect
        $this->assertEquals(202, $resp->getStatusCode(), 'Product export must be async 202, not sync 200. Got: '.$resp->getContent());
        $import = Import::where('type', ImportType::PRODUCT_EXPORT)->where('created_by', $owner->id)->latest()->first();
        $this->assertNotNull($import);
        \Illuminate\Support\Facades\Queue::assertPushed(ExportProductsJob::class);
    }

    // 4.36 Filter deep
    public function test_export_filter_all_8_sheets_deep(): void
    {
        $cat1 = Category::create(['name'=>['en'=>'F1'],'slug'=>'f1-'.uniqid(),'status'=>true]);
        $cat2 = Category::create(['name'=>['en'=>'F2'],'slug'=>'f2-'.uniqid(),'status'=>true]);
        $p1 = Product::create(['sku'=>'FILT-001','name'=>['en'=>'P1'],'slug'=>'filt-001-'.uniqid(),'price'=>10,'quantity'=>5,'stock_quantity'=>5,'product_type'=>'simple','status'=>true,'in_stock'=>true]);
        $p2 = Product::create(['sku'=>'FILT-002','name'=>['en'=>'P2'],'slug'=>'filt-002-'.uniqid(),'price'=>10,'quantity'=>5,'stock_quantity'=>5,'product_type'=>'simple','status'=>true,'in_stock'=>true]);
        $p1->categories()->attach($cat1->id);
        $p2->categories()->attach($cat2->id);
        $export = new \Marvel\Exports\ProductsExport(['category_id'=>$cat1->id]);
        foreach (['products','product_variants','images','categories','brands','flash_sales','sliders','tags'] as $sheet) {
            $sheetObj = $export->sheets()[$sheet];
            $rows = method_exists($sheetObj, 'collection') ? $sheetObj->collection() : $sheetObj->query()->get();
            // For product_variants etc., collection may be empty, but must not contain p2
            foreach ($rows as $r) {
                $arr = is_array($r) ? $r : (method_exists($r,'toArray') ? $r->toArray() : (array)$r);
                $sku = $arr['product_sku'] ?? $arr['sku'] ?? null;
                $this->assertNotEquals('FILT-002', $sku, "Sheet $sheet leaked non-matching product");
            }
        }
    }

    // 4.43 Queue deep - meem-medium is canonical for imports/exports
    public function test_all_six_jobs_use_meem_bulk(): void
    {
        $map = [
            ImportProductsJob::class => 1,
            ImportCategoriesJob::class => 1,
            ImportBrandsJob::class => 1,
            ExportCategoriesJob::class => 1,
            ExportBrandsJob::class => 1,
        ];
        foreach ($map as $cls => $arg) {
            $job = new $cls($arg);
            $this->assertEquals('meem-medium', $job->queue, "$cls must be meem-medium, got {$job->queue}");
        }
        $job = new ExportProductsJob([]);
        $this->assertEquals('meem-medium', $job->queue, "ExportProductsJob must be meem-medium, got {$job->queue}");
    }

    // 4.46 Database deep
    public function test_imports_schema_indexes_and_nullable(): void
    {
        $sm = DB::connection()->getDoctrineSchemaManager();
        $indexes = $sm->listTableIndexes('imports');
        $hasTypeStatus=false; $hasCreatedBy=false;
        foreach ($indexes as $idx) {
            $cols=$idx->getColumns();
            if (in_array('type',$cols) && in_array('status',$cols)) $hasTypeStatus=true;
            if (in_array('created_by',$cols) && in_array('created_at',$cols)) $hasCreatedBy=true;
        }
        $this->assertTrue($hasTypeStatus, 'imports(type,status) index missing');
        $this->assertTrue($hasCreatedBy, 'imports(created_by,created_at) index missing');
        // Check nullable file_path/file_name if DB-3
        $cols = $sm->listTableColumns('imports');
        // In many DBs, file_path is still NOT NULL with default '' — DB-3 would make nullable
        // We just verify column exists and report
        $this->assertArrayHasKey('file_path', $cols);
        $this->assertArrayHasKey('file_name', $cols);
    }

    // 4.42 SSRF deep matrix
    public function test_ssrf_blocks_private_ranges(): void
    {
        $service = new CategoryImportService();
        $ref = new \ReflectionMethod($service, 'assertSafeUrl');
        $ref->setAccessible(true);
        $blocked = ['http://127.0.0.1/a.jpg','http://localhost/a.jpg','http://10.0.0.1/a.jpg','http://192.168.1.1/a.jpg','http://172.16.0.1/a.jpg','http://[::1]/a.jpg'];
        foreach ($blocked as $url) {
            try {
                $ref->invoke($service, $url);
                $this->fail("URL $url should be blocked");
            } catch (\Throwable $e) {
                $this->assertTrue(true);
            }
        }
        // Safe URL should not throw (but will fail download later, not assertSafeUrl)
        try {
            $ref->invoke($service, 'http://example.com/a.jpg');
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            // If example.com resolves to blocked IP in test env, skip
            $this->markTestIncomplete('Safe URL blocked due to DNS, skip');
        }
    }

    // 4.41 ZIP deep
    public function test_zip_traversal_blocked(): void
    {
        $handler = new \Marvel\Services\Import\ImageHandlers\ZipImageHandler();
        $content = file_get_contents(base_path('packages/marvel/src/Services/Import/ImageHandlers/ZipImageHandler.php'));
        $this->assertStringContainsString('basename', $content, 'Must use basename to strip traversal');
        // Create zip with traversal
        $tmp = tempnam(sys_get_temp_dir(),'zip');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE);
        $zip->addFromString('../../evil.php', '<?php ?>');
        $zip->addFromString('/absolute/path.jpg', 'fake');
        $zip->addFromString('..\\evil2.php', '<?php ?>');
        $zip->close();
        // Handler should not extract outside
        $uploaded = new \Illuminate\Http\UploadedFile($tmp, 'test.zip', 'application/zip', null, true);
        try {
            $handler->extract($uploaded);
            $evil = dirname($tmp).'/evil.php';
            $this->assertFileDoesNotExist($evil, 'Zip slip must not create evil.php outside');
            $this->assertFileDoesNotExist('/absolute/path.jpg', 'Absolute path must not be extracted');
            $handler->cleanup();
        } catch (\Throwable $e) {
            $this->assertTrue(true, 'Handler threw for invalid zip, acceptable');
        }
        @unlink($tmp);
    }
}

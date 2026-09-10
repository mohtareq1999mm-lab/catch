<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as Perm;
use Marvel\Jobs\ImportBrandsJob;
use Marvel\Jobs\ImportCategoriesJob;
use Marvel\Services\Import\BrandImportService;
use Marvel\Services\Import\CategoryImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CategoryBrandImportTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(array $perms = []): User
    {
        foreach ($perms as $p) Permission::findOrCreate($p, 'api');
        Permission::findOrCreate('api');
        $role = Role::create(['name'=>'r'.uniqid(),'guard_name'=>'api','display_name'=>'r']);
        foreach ($perms as $p) $role->givePermissionTo($p);

        $u = User::create(['name'=>'u'.uniqid(),'email'=>uniqid().'@test.local','password'=>Hash::make('password'),'email_verified_at'=>now(),'is_active'=>true,'type'=>'admin']);
        $u->assignRole($role);
        foreach ($perms as $p) $u->givePermissionTo($p);

        return $u;
    }

    private function writeSheet(array $headers, array $rows, string $title = 'Sheet1'): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle($title);
        $sheet->fromArray($headers, null, 'A1');
        $r=2;
        foreach ($rows as $row) {
            $data=[];
            foreach ($headers as $h) $data[] = $row[$h] ?? '';
            $sheet->fromArray($data, null, "A{$r}");
            $r++;
        }
        $tmp=tempnam(sys_get_temp_dir(),'cat');
        (new Xlsx($ss))->save($tmp);
        $ss->disconnectWorksheets();
        return $tmp;
    }

    // Category create
    public function test_category_import_create(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin([Perm::IMPORT_CATEGORY]);
        $headers = ['name_en','name_ar','details_en','details_ar','parent_name_en','status','is_featured','image_desktop_url','image_mobile_url'];
        $tmp = $this->writeSheet($headers, [
            ['name_en'=>'CatCreate1','name_ar'=>'فئة1','status'=>1,'is_featured'=>0],
        ], 'categories');
        $path = 'imports/cat_'.uniqid().'.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmp));
        $import = Import::create(['type'=>'category-import','file_path'=>$path,'file_name'=>'c.xlsx','status'=>'pending','total_rows'=>1,'created_by'=>$user->id]);
        (new ImportCategoriesJob($import->id))->handle();
        $import->refresh();
        $this->assertEquals('completed', $import->status);
        $this->assertDatabaseHas('categories', ['slug'=>'catcreate1']);
        // Check translation
        $cat = Category::where('slug','catcreate1')->first();
        $this->assertEquals('CatCreate1', $cat->getTranslation('name','en'));
        @unlink($tmp);
    }

    public function test_category_import_update_does_not_duplicate(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin([Perm::IMPORT_CATEGORY]);
        $cat = Category::create(['name'=>['en'=>'DupCat','ar'=>'ف'],'slug'=>'dupcat','details'=>['en'=>'d'],'status'=>true,'is_featured'=>false]);
        $headers = ['name_en','name_ar','details_en','details_ar','parent_name_en','status','is_featured','image_desktop_url','image_mobile_url'];
        $tmp = $this->writeSheet($headers, [
            ['name_en'=>'DupCat','name_ar'=>'فئة محدثة','details_en'=>'new','status'=>1,'is_featured'=>1],
        ], 'categories');
        $path = 'imports/cat_'.uniqid().'.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmp));
        $import = Import::create(['type'=>'category-import','file_path'=>$path,'file_name'=>'c.xlsx','status'=>'pending','total_rows'=>1,'created_by'=>$user->id]);
        (new ImportCategoriesJob($import->id))->handle();
        $import->refresh();
        $this->assertEquals('completed', $import->status);
        $this->assertEquals(1, Category::where('slug','dupcat')->count());
        $cat->refresh();
        $this->assertEquals('فئة محدثة', $cat->getTranslation('name','ar'));
        @unlink($tmp);
    }

    public function test_category_import_parent_assignment(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin([Perm::IMPORT_CATEGORY]);
        $headers = ['name_en','name_ar','details_en','details_ar','parent_name_en','status','is_featured','image_desktop_url','image_mobile_url'];
        $tmp = $this->writeSheet($headers, [
            ['name_en'=>'ParentCat','name_ar'=>'أب','status'=>1,'is_featured'=>0],
            ['name_en'=>'ChildCat','name_ar'=>'ابن','parent_name_en'=>'ParentCat','status'=>1,'is_featured'=>0],
        ], 'categories');
        $path = 'imports/cat_'.uniqid().'.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmp));
        $import = Import::create(['type'=>'category-import','file_path'=>$path,'file_name'=>'c.xlsx','status'=>'pending','total_rows'=>2,'created_by'=>$user->id]);
        (new ImportCategoriesJob($import->id))->handle();
        $import->refresh();
        $this->assertEquals('completed', $import->status);
        $child = Category::where('slug','childcat')->first();
        $parent = Category::where('slug','parentcat')->first();
        $this->assertNotNull($child);
        $this->assertEquals($parent->id, $child->parent_id);
        @unlink($tmp);
    }

    public function test_category_import_child_before_parent_still_assigns(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin([Perm::IMPORT_CATEGORY]);
        $headers = ['name_en','name_ar','details_en','details_ar','parent_name_en','status','is_featured','image_desktop_url','image_mobile_url'];
        $tmp = $this->writeSheet($headers, [
            ['name_en'=>'ChildFirst','name_ar'=>'ابن','parent_name_en'=>'ParentLater','status'=>1,'is_featured'=>0],
            ['name_en'=>'ParentLater','name_ar'=>'أب','status'=>1,'is_featured'=>0],
        ], 'categories');
        $path = 'imports/cat_'.uniqid().'.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmp));
        $import = Import::create(['type'=>'category-import','file_path'=>$path,'file_name'=>'c.xlsx','status'=>'pending','total_rows'=>2,'created_by'=>$user->id]);
        (new ImportCategoriesJob($import->id))->handle();
        $import->refresh();
        $this->assertEquals('completed', $import->status);
        $child = Category::where('slug','childfirst')->first();
        $parent = Category::where('slug','parentlater')->first();
        $this->assertEquals($parent->id, $child->parent_id, 'Child-before-parent must still resolve due to two-phase design');
        @unlink($tmp);
    }

    public function test_category_import_partial_success(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin([Perm::IMPORT_CATEGORY]);
        $headers = ['name_en','name_ar','details_en','details_ar','parent_name_en','status','is_featured','image_desktop_url','image_mobile_url'];
        $tmp = $this->writeSheet($headers, [
            ['name_en'=>'GoodCat','name_ar'=>'جيد','status'=>1,'is_featured'=>0],
            ['name_en'=>'','name_ar'=>'سيء','status'=>1,'is_featured'=>0], // missing name_en fails
        ], 'categories');
        $path = 'imports/cat_'.uniqid().'.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmp));
        $import = Import::create(['type'=>'category-import','file_path'=>$path,'file_name'=>'c.xlsx','status'=>'pending','total_rows'=>2,'created_by'=>$user->id]);
        (new ImportCategoriesJob($import->id))->handle();
        $import->refresh();
        $this->assertEquals('completed_with_errors', $import->status);
        $this->assertEquals(1, $import->success_rows);
        $this->assertEquals(1, $import->failed_rows);
        @unlink($tmp);
    }

    public function test_brand_import_create_and_update(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin([Perm::IMPORT_BRAND]);
        $headers = ['name_en','name_ar','details_en','details_ar','status','image_desktop_url','image_mobile_url'];
        $tmp = $this->writeSheet($headers, [
            ['name_en'=>'BrandOne','name_ar'=>'براند1','status'=>1],
        ], 'brands');
        $path = 'imports/brand_'.uniqid().'.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmp));
        $import = Import::create(['type'=>'brand-import','file_path'=>$path,'file_name'=>'b.xlsx','status'=>'pending','total_rows'=>1,'created_by'=>$user->id]);
        (new ImportBrandsJob($import->id))->handle();
        $import->refresh();
        $this->assertEquals('completed', $import->status);
        $this->assertDatabaseHas('brands', ['slug'=>'brandone']);
        // update
        $tmp2 = $this->writeSheet($headers, [
            ['name_en'=>'BrandOne','name_ar'=>'محدث','status'=>1],
        ], 'brands');
        $path2 = 'imports/brand_'.uniqid().'.xlsx';
        Storage::disk('public')->put($path2, file_get_contents($tmp2));
        $import2 = Import::create(['type'=>'brand-import','file_path'=>$path2,'file_name'=>'b2.xlsx','status'=>'pending','total_rows'=>1,'created_by'=>$user->id]);
        (new ImportBrandsJob($import2->id))->handle();
        $import2->refresh();
        $this->assertEquals('completed', $import2->status);
        $this->assertEquals(1, Brand::where('slug','brandone')->count());
        $brand = Brand::where('slug','brandone')->first();
        $this->assertEquals('محدث', $brand->getTranslation('name','ar'));
        @unlink($tmp); @unlink($tmp2);
    }

    public function test_brand_import_partial_success(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin([Perm::IMPORT_BRAND]);
        $headers = ['name_en','name_ar','details_en','details_ar','status','image_desktop_url','image_mobile_url'];
        $tmp = $this->writeSheet($headers, [
            ['name_en'=>'GoodBrand','name_ar'=>'جيد','status'=>1],
            ['name_en'=>'','name_ar'=>'سيء','status'=>1],
        ], 'brands');
        $path = 'imports/brand_'.uniqid().'.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmp));
        $import = Import::create(['type'=>'brand-import','file_path'=>$path,'file_name'=>'b.xlsx','status'=>'pending','total_rows'=>2,'created_by'=>$user->id]);
        (new ImportBrandsJob($import->id))->handle();
        $import->refresh();
        $this->assertEquals('completed_with_errors', $import->status);
        @unlink($tmp);
    }

    public function test_category_import_withMultipleSheets_only_first_sheet_processed(): void
    {
        // After D-1, CategoriesImport should use WithMultipleSheets and only process intended sheet
        // Test that stray second sheet does not double-process
        Storage::fake('public');
        $user = $this->makeAdmin([Perm::IMPORT_CATEGORY]);
        $ss = new Spreadsheet();
        $headers = ['name_en','name_ar','details_en','details_ar','parent_name_en','status','is_featured','image_desktop_url','image_mobile_url'];
        $sheet1 = $ss->getActiveSheet();
        $sheet1->setTitle('Sheet1');
        $sheet1->fromArray($headers, null, 'A1');
        $sheet1->fromArray(['OnlyCat','فقط','d','d','','1','0','',''], null, 'A2');
        $sheet2 = $ss->createSheet();
        $sheet2->setTitle('Notes');
        $sheet2->fromArray($headers, null, 'A1');
        $sheet2->fromArray(['StrayCat','شارد','d','d','','1','0','',''], null, 'A2');
        $tmp=tempnam(sys_get_temp_dir(),'cat');
        (new Xlsx($ss))->save($tmp);
        $path='imports/cat_'.uniqid().'.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmp));
        $import = Import::create(['type'=>'category-import','file_path'=>$path,'file_name'=>'c.xlsx','status'=>'pending','total_rows'=>1,'created_by'=>$user->id]);
        (new ImportCategoriesJob($import->id))->handle();
        $import->refresh();
        // If bug BE-014 exists, both sheets processed => 2 successes. If fixed, only 1.
        // Expected after fix: 1 success
        // We assert correct behavior (1) which will fail on buggy code (2)
        $this->assertEquals(1, $import->success_rows, 'Stray second sheet must not be processed (BE-014). Got '.$import->success_rows);
        // Also ensure OnlyCat exists, StrayCat should not if correctly fixing, but currently both would exist
        $this->assertDatabaseHas('categories', ['slug'=>'onlycat']);
        // This will expose the bug if StrayCat also exists
        $hasStray = Category::where('slug','straycat')->exists();
        $this->assertFalse($hasStray, 'Stray sheet category must not be created');
        @unlink($tmp);
        $ss->disconnectWorksheets();
    }

    public function test_brand_import_withMultipleSheets_only_first_sheet_processed(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin([Perm::IMPORT_BRAND]);
        $ss = new Spreadsheet();
        $headers = ['name_en','name_ar','details_en','details_ar','status','image_desktop_url','image_mobile_url'];
        $sheet1 = $ss->getActiveSheet();
        $sheet1->setTitle('Sheet1');
        $sheet1->fromArray($headers, null, 'A1');
        $sheet1->fromArray(['OnlyBrand','فقط','d','d','1','',''], null, 'A2');
        $sheet2 = $ss->createSheet();
        $sheet2->setTitle('Notes');
        $sheet2->fromArray($headers, null, 'A1');
        $sheet2->fromArray(['StrayBrand','شارد','d','d','1','',''], null, 'A2');
        $tmp=tempnam(sys_get_temp_dir(),'brand');
        (new Xlsx($ss))->save($tmp);
        $path='imports/brand_'.uniqid().'.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmp));
        $import = Import::create(['type'=>'brand-import','file_path'=>$path,'file_name'=>'b.xlsx','status'=>'pending','total_rows'=>1,'created_by'=>$user->id]);
        (new ImportBrandsJob($import->id))->handle();
        $import->refresh();
        $this->assertEquals(1, $import->success_rows, 'Brand stray sheet must not be processed BE-014');
        $this->assertDatabaseHas('brands', ['slug'=>'onlybrand']);
        $this->assertDatabaseMissing('brands', ['slug'=>'straybrand']);
        @unlink($tmp);
        $ss->disconnectWorksheets();
    }

    public function test_import_retry_idempotency_product_image_not_duplicated(): void
    {
        // Simulate two runs of same file: second should not duplicate due to natural key upsert
        Storage::fake('public');
        $user = $this->makeAdmin([Perm::IMPORT_CATEGORY]);
        $headers = ['name_en','name_ar','details_en','details_ar','parent_name_en','status','is_featured','image_desktop_url','image_mobile_url'];
        $tmp = $this->writeSheet($headers, [
            ['name_en'=>'IdemCat','name_ar'=>'ف','status'=>1,'is_featured'=>0],
        ], 'categories');
        $path = 'imports/cat_'.uniqid().'.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmp));
        $import = Import::create(['type'=>'category-import','file_path'=>$path,'file_name'=>'c.xlsx','status'=>'pending','total_rows'=>1,'created_by'=>$user->id]);
        // First run
        (new ImportCategoriesJob($import->id))->handle();
        $countAfterFirst = Category::where('slug','idemcat')->count();
        // Reset to pending to simulate retry from row 1 (bug BE-007)
        $import->update(['status'=>'pending']);
        // Second run on same file
        (new ImportCategoriesJob($import->id))->handle();
        $countAfterSecond = Category::where('slug','idemcat')->count();
        $this->assertEquals(1, $countAfterFirst);
        $this->assertEquals(1, $countAfterSecond, 'Retry must not duplicate category (idempotency)');
        @unlink($tmp);
    }

    public function test_transaction_rollback_per_batch_category(): void
    {
        // This is a conceptual test: if a batch fails, previous batches remain committed (correct per BE-008 fix)
        // We verify that successful rows before a later failure remain in DB (partial success not whole rollback)
        Storage::fake('public');
        $user = $this->makeAdmin([Perm::IMPORT_CATEGORY]);
        $headers = ['name_en','name_ar','details_en','details_ar','parent_name_en','status','is_featured','image_desktop_url','image_mobile_url'];
        $tmp = $this->writeSheet($headers, [
            ['name_en'=>'TxGood','name_ar'=>'جيد','status'=>1,'is_featured'=>0],
            ['name_en'=>'','name_ar'=>'سيء','status'=>1,'is_featured'=>0], // fails
            ['name_en'=>'TxGood2','name_ar'=>'جيد2','status'=>1,'is_featured'=>0],
        ], 'categories');
        $path = 'imports/cat_'.uniqid().'.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmp));
        $import = Import::create(['type'=>'category-import','file_path'=>$path,'file_name'=>'c.xlsx','status'=>'pending','total_rows'=>3,'created_by'=>$user->id]);
        (new ImportCategoriesJob($import->id))->handle();
        $import->refresh();
        $this->assertEquals('completed_with_errors', $import->status);
        $this->assertDatabaseHas('categories', ['slug'=>'txgood']);
        $this->assertDatabaseHas('categories', ['slug'=>'txgood2']);
        $this->assertEquals(2, $import->success_rows, 'Partial success: committed rows must remain (not whole-file rollback)');
        @unlink($tmp);
    }
}

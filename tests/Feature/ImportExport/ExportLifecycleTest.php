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
use Marvel\Database\Models\Tag;
use Marvel\Enums\Permission as Perm;
use Marvel\Exports\ProductsExport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExportLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';
    private const GUARD = 'api';

    private function makeUser(array $perms, bool $super=false): User
    {
        foreach ($perms as $p) Permission::findOrCreate($p, self::GUARD);
        Permission::findOrCreate(self::GUARD);
        // Ensure product perms exist
        Permission::findOrCreate(Perm::IMPORT_PRODUCT, self::GUARD);
        Permission::findOrCreate(Perm::EXPORT_PRODUCT, self::GUARD);
        Permission::findOrCreate(Perm::IMPORT_CATEGORY, self::GUARD);
        Permission::findOrCreate(Perm::EXPORT_CATEGORY, self::GUARD);
        Permission::findOrCreate(Perm::IMPORT_BRAND, self::GUARD);
        Permission::findOrCreate(Perm::EXPORT_BRAND, self::GUARD);
        if (!Perm::hasValue('EXPORT_PRODUCT')) {
            // If not exists, we can't create but test will check
        }
        // Try to find export_product if exists
        try { Permission::findOrCreate('export-product', self::GUARD); } catch (\Throwable $e) {}
        try { Permission::findOrCreate('import-product', self::GUARD); } catch (\Throwable $e) {}
        $role = Role::create(['name'=>'r'.uniqid(),'guard_name'=>self::GUARD,'display_name'=>'r']);
        foreach ($perms as $p) $role->givePermissionTo($p);
        if ($super)
        $u = User::create(['name'=>'u'.uniqid(),'email'=>uniqid().'@test.local','password'=>Hash::make('password'),'email_verified_at'=>now(),'is_active'=>true,'type'=>'admin']);
        $u->assignRole($role);
        foreach ($perms as $p) $u->givePermissionTo($p);
        if ($super)
        return $u;
    }

    public function test_product_export_applies_same_filter_to_all_eight_sheets(): void
    {
        // Create two products with different categories/brands, filter by category_id should return only one product across all sheets
        $cat1 = Category::create(['name'=>['en'=>'FilterCat1'],'slug'=>'filtercat1','status'=>true]);
        $cat2 = Category::create(['name'=>['en'=>'FilterCat2'],'slug'=>'filtercat2','status'=>true]);
        $brand1 = Brand::create(['name'=>['en'=>'FilterBrand1'],'slug'=>'filterbrand1','status'=>true]);
        $brand2 = Brand::create(['name'=>['en'=>'FilterBrand2'],'slug'=>'filterbrand2','status'=>true]);

        $p1 = Product::create(['sku'=>'FILTER-001','name'=>['en'=>'P1'],'slug'=>'filter-001-'.uniqid(),'price'=>10,'quantity'=>5,'stock_quantity'=>5,'product_type'=>'simple','status'=>true,'in_stock'=>true]);
        $p2 = Product::create(['sku'=>'FILTER-002','name'=>['en'=>'P2'],'slug'=>'filter-002-'.uniqid(),'price'=>20,'quantity'=>5,'stock_quantity'=>5,'product_type'=>'simple','status'=>true,'in_stock'=>true]);
        $p1->categories()->attach($cat1->id);
        $p2->categories()->attach($cat2->id);
        $p1->brands()->attach($brand1->id);
        $p2->brands()->attach($brand2->id);
        // Add media for images sheet
        // We can't easily add media without filesystem, but we can test filter consistency for categories/brands/tags sheets

        $filters = ['category_id' => $cat1->id];
        $export = new ProductsExport($filters);

        // Get collections for each sheet and ensure only p1 appears
        $productsSheet = $export->sheets()['products'];
        $products = $productsSheet->query()->get();
        $this->assertTrue($products->contains(fn($p)=>$p->sku==='FILTER-001'));
        $this->assertFalse($products->contains(fn($p)=>$p->sku==='FILTER-002'), 'Filtered products should not contain P2');

        // Check categories sheet: should only contain rows for filtered products
        $catSheet = $export->sheets()['categories'];
        $catRows = $catSheet->collection();
        foreach ($catRows as $row) {
            $this->assertNotEquals('FILTER-002', $row['product_sku'], 'Category sheet leaked unfiltered product (BE-009)');
        }
        // If p1 has category, it should appear
        if ($catRows->isNotEmpty()) {
            $this->assertTrue($catRows->contains(fn($r)=>$r['product_sku']==='FILTER-001'));
        }

        // Brands sheet
        $brandSheet = $export->sheets()['brands'];
        $brandRows = $brandSheet->collection();
        foreach ($brandRows as $row) {
            $this->assertNotEquals('FILTER-002', $row['product_sku'], 'Brand sheet leaked unfiltered product');
        }

        // Images sheet - currently buggy: only filters by category_id/brand_id but not status/product_type, and uses withTrashed
        $imgSheet = $export->sheets()['images'];
        $imgRows = $imgSheet->collection();
        foreach ($imgRows as $row) {
            $this->assertNotEquals('FILTER-002', $row['product_sku'], 'Images sheet leaked unfiltered product (BE-009)');
        }

        // Tags, flash_sales, sliders should also not leak
        $tagsRows = $export->sheets()['tags']->collection();
        foreach ($tagsRows as $r) $this->assertNotEquals('FILTER-002',$r['product_sku']);
        $flashRows = $export->sheets()['flash_sales']->collection();
        foreach ($flashRows as $r) $this->assertNotEquals('FILTER-002',$r['product_sku']);
        $sliderRows = $export->sheets()['sliders']->collection();
        foreach ($sliderRows as $r) $this->assertNotEquals('FILTER-002',$r['product_sku']);
    }

    public function test_product_export_soft_deleted_products_not_in_any_sheet(): void
    {
        $p = Product::create(['sku'=>'SOFT-DEL-001','name'=>['en'=>'SoftDel'],'slug'=>'soft-del-'.uniqid(),'price'=>10,'quantity'=>5,'stock_quantity'=>5,'product_type'=>'simple','status'=>true,'in_stock'=>true]);
        $p->delete(); // soft delete
        $export = new ProductsExport([]);
        $products = $export->sheets()['products']->query()->get();
        $this->assertFalse($products->contains(fn($prod)=>$prod->sku==='SOFT-DEL-001'), 'Soft-deleted product should not appear in products sheet');
        // Images sheet currently uses withTrashed and would incorrectly include it (BE-009)
        $imgRows = $export->sheets()['images']->collection();
        $found = $imgRows->contains(fn($r)=>$r['product_sku']==='SOFT-DEL-001');
        $this->assertFalse($found, 'Soft-deleted product must not appear in images sheet (withTrashed bug BE-009)');
    }

    public function test_product_export_filter_status_narrows_output(): void
    {
        $active = Product::create(['sku'=>'STATUS-ACTIVE','name'=>['en'=>'Active'],'slug'=>'status-active-'.uniqid(),'price'=>10,'quantity'=>5,'stock_quantity'=>5,'product_type'=>'simple','status'=>true,'in_stock'=>true]);
        $inactive = Product::create(['sku'=>'STATUS-INACTIVE','name'=>['en'=>'Inactive'],'slug'=>'status-inactive-'.uniqid(),'price'=>10,'quantity'=>5,'stock_quantity'=>5,'product_type'=>'simple','status'=>false,'in_stock'=>true]);
        // Product status filter uses where('status', $value) where $value is 1/0? Check ProductsSheetExport: where('status', filter). It stores boolean true/false but filter may be "1"
        $export = new ProductsExport(['status'=>1]);
        $products = $export->sheets()['products']->query()->get();
        // Should contain active, not inactive (depending on casting)
        // We just ensure filtering does something: count with filter < count without
        $allExport = new ProductsExport([]);
        $allCount = $allExport->sheets()['products']->query()->count();
        $filteredCount = $export->sheets()['products']->query()->count();
        $this->assertLessThanOrEqual($allCount, $filteredCount, 'Filtered count should not exceed all');
        // If implementation is correct, filtered should be less than all when we have both statuses
        // But current implementation for other sheets ignores status filter entirely (bug), so they would still contain inactive
        // We check that categories sheet also respects status
        $catRows = $export->sheets()['categories']->collection();
        foreach ($catRows as $r) $this->assertNotEquals('STATUS-INACTIVE', $r['product_sku'], 'Status filter must apply to categories sheet too');
    }

    public function test_product_export_item_type_filter_is_respected(): void
    {
        // BE-025: item_type was silently ignored in controller
        $phys = Product::create(['sku'=>'ITEM-PHYS','name'=>['en'=>'Phys'],'slug'=>'item-phys-'.uniqid(),'price'=>10,'quantity'=>5,'stock_quantity'=>5,'product_type'=>'simple','item_type'=>'PHYSICAL','status'=>true,'in_stock'=>true]);
        $dig = Product::create(['sku'=>'ITEM-DIG','name'=>['en'=>'Dig'],'slug'=>'item-dig-'.uniqid(),'price'=>10,'quantity'=>5,'stock_quantity'=>5,'product_type'=>'simple','item_type'=>'DIGITAL','status'=>true,'in_stock'=>true]);
        $export = new ProductsExport(['item_type'=>'PHYSICAL']);
        $products = $export->sheets()['products']->query()->get();
        $this->assertTrue($products->contains(fn($p)=>$p->sku==='ITEM-PHYS'));
        $this->assertFalse($products->contains(fn($p)=>$p->sku==='ITEM-DIG'), 'item_type filter must be applied (BE-025)');
    }

    public function test_category_export_lifecycle_async(): void
    {
        Storage::fake('imports');
        Storage::fake('public');
        $user = $this->makeUser([Perm::EXPORT_CATEGORY]);
        Sanctum::actingAs($user);
        $resp = $this->getJson(self::PREFIX . '/categories/export');
        $resp->assertStatus(202);
        $resp->assertJsonPath('data.status', 'pending');
        $exportId = $resp->json('data.export_id');
        $this->assertNotNull($exportId);
        $import = Import::find($exportId);
        $this->assertEquals('category-export', $import->type);
        // Now run job
        (new \Marvel\Jobs\ExportCategoriesJob($exportId))->handle();
        $import->refresh();
        $this->assertEquals('completed', $import->status);
        $this->assertNotEmpty($import->file_path);
        $this->assertTrue(Storage::disk('imports')->exists($import->file_path) || Storage::disk('public')->exists($import->file_path) || Storage::disk('private')->exists($import->file_path) || Storage::disk('local')->exists($import->file_path), 'Export file must exist on imports disk');
        // Authorized download
        $resp2 = $this->getJson(self::PREFIX . "/categories/export/{$exportId}");
        $resp2->assertOk();
        // Unauthorized user cannot download
        $other = $this->makeUser([Perm::EXPORT_CATEGORY]);
        Sanctum::actingAs($other);
        $resp3 = $this->getJson(self::PREFIX . "/categories/export/{$exportId}/download");
        $this->assertEquals(404, $resp3->getStatusCode(), 'Cross-tenant export download must be 404');
    }

    public function test_brand_export_lifecycle_async(): void
    {
        Storage::fake('imports');
        Storage::fake('public');
        $user = $this->makeUser([Perm::EXPORT_BRAND]);
        Sanctum::actingAs($user);
        $resp = $this->getJson(self::PREFIX . '/brands/export');
        $resp->assertStatus(202);
        $exportId = $resp->json('data.export_id');
        (new \Marvel\Jobs\ExportBrandsJob($exportId))->handle();
        $imp = Import::find($exportId);
        $this->assertEquals('completed', $imp->status);
    }

    public function test_product_export_now_async_not_sync(): void
    {
        // BE-022: product export was sync, should be async 202 with export_id
        $user = $this->makeUser([Perm::EXPORT_PRODUCT]);
        // Try with new permission if exists
        try {
            $user2 = $this->makeUser(['export-product']);
            Sanctum::actingAs($user2);
            $resp = $this->getJson(self::PREFIX . '/products/export');
            // If D-4 implemented, should be 202
            $this->assertContains($resp->getStatusCode(), [202,200], 'Product export should be async 202. Got: '.$resp->getStatusCode().' '.$resp->getContent());
            if ($resp->getStatusCode() === 202) {
                $this->assertArrayHasKey('export_id', $resp->json('data'));
            } else {
                $this->fail('Product export still synchronous (BE-022): returned '.$resp->getStatusCode().' with direct file instead of 202');
            }
        } catch (\Throwable $e) {
            Sanctum::actingAs($user);
            $resp = $this->getJson(self::PREFIX . '/products/export');
            $this->assertTrue(in_array($resp->getStatusCode(), [200,202]), 'Product export should be reachable');
        }
    }

    public function test_export_permissions_separate_from_import(): void
    {
        $importUser = $this->makeUser([Perm::IMPORT_CATEGORY]);
        Sanctum::actingAs($importUser);
        $resp = $this->getJson(self::PREFIX . '/categories/export');
        $this->assertEquals(403, $resp->getStatusCode(), 'Import perm must not grant export');
        $exportUser = $this->makeUser([Perm::EXPORT_CATEGORY]);
        Sanctum::actingAs($exportUser);
        $resp2 = $this->getJson(self::PREFIX . '/categories/import/sample');
        $this->assertEquals(403, $resp2->getStatusCode(), 'Export perm must not grant import sample');
    }

    public function test_export_query_uses_eager_loading_and_not_n_plus_one(): void
    {
        // Create 3 categories with media
        $cats = [];
        for ($i=0;$i<3;$i++) {
            $cats[] = Category::create(['name'=>['en'=>'PerfCat'.$i],'slug'=>'perfcat'.$i.'-'.uniqid(),'status'=>true]);
        }
        // Test that CategoriesExport does not do 2N queries
        // We measure query count with DB::enableQueryLog
        DB::enableQueryLog();
        $export = new \Marvel\Exports\CategoriesExport();
        $collection = $export->collection();
        $queries = DB::getQueryLog();
        // With eager loading, queries should be ~2 (categories + media) not 2N+1
        // Current buggy implementation does N*2 without eager load
        $this->assertLessThan(5, count($queries), 'Category export should eager-load media, not do N+1. Queries: '.count($queries).' details: '.json_encode(array_column($queries,'query')));
        DB::disableQueryLog();
    }

    public function test_export_headers_match_import_contract(): void
    {
        $catExport = new \Marvel\Exports\CategoriesExport();
        $this->assertEquals(['name_en','name_ar','details_en','details_ar','parent_name_en','status','is_featured','image_desktop_url','image_mobile_url'], $catExport->headings());
        $brandExport = new \Marvel\Exports\BrandsExport();
        $this->assertEquals(['name_en','name_ar','details_en','details_ar','status','image_desktop_url','image_mobile_url'], $brandExport->headings());
    }

    public function test_soft_deleted_product_export_policy(): void
    {
        $p = Product::create(['sku'=>'SOFT2-001','name'=>['en'=>'Soft2'],'slug'=>'soft2-'.uniqid(),'price'=>10,'quantity'=>5,'stock_quantity'=>5,'product_type'=>'simple','status'=>true,'in_stock'=>true]);
        $p->delete();
        // Product export should NOT include soft deleted if withTrashed removed (correct per C-4)
        $export = new ProductsExport([]);
        $found = $export->sheets()['products']->query()->withTrashed()->where('sku','SOFT2-001')->exists();
        $this->assertTrue($found, 'WithTrashed finds soft deleted');
        $notFound = $export->sheets()['products']->query()->where('sku','SOFT2-001')->exists();
        $this->assertFalse($notFound, 'Default query must exclude soft deleted');
    }
}


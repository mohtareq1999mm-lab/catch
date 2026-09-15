<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\Slider;
use Marvel\Database\Models\Tag;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Jobs\ExportProductsJob;
use Marvel\Services\Import\ProductImportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use Illuminate\Support\Str;

class CompleteProductExportImportTest extends TestCase
{
    use RefreshDatabase;

    protected function createUser(): User
    {
        return User::create([
            'name' => 'Tester',
            'email' => 'tester-'.uniqid().'@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
    }

    public function test_insert_all_data_and_export_import_roundtrip(): void
    {
        Storage::fake('imports');
        $user = $this->createUser();

        // 1. Insert Categories
        $cat1 = Category::create(['name' => ['en' => 'Cat One', 'ar' => 'فئة 1'], 'slug' => 'cat-one-'.uniqid(), 'status' => 1]);
        $cat2 = Category::create(['name' => ['en' => 'Cat Two', 'ar' => 'فئة 2'], 'slug' => 'cat-two-'.uniqid(), 'status' => 1]);

        // 2. Insert Brands
        $brand1 = Brand::create(['name' => ['en' => 'Brand A', 'ar' => 'ماركة أ'], 'slug' => 'brand-a-'.uniqid(), 'status' => 1]);
        $brand2 = Brand::create(['name' => ['en' => 'Brand B', 'ar' => 'ماركة ب'], 'slug' => 'brand-b-'.uniqid(), 'status' => 1]);

        // 3. Insert Tags, FlashSales, Sliders
        $tag1 = Tag::create(['name' => ['en' => 'Tag1'], 'slug' => 'tag1-'.uniqid()]);
        $tag2 = Tag::create(['name' => ['en' => 'Tag2'], 'slug' => 'tag2-'.uniqid()]);
        $flash = FlashSale::create([
            'title' => ['en' => 'Flash 1'], 'slug' => 'flash-1-'.uniqid(),
            'status' => true, 'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(), 'type' => 'percentage', 'discount' => 10
        ]);
        $slider = Slider::create(['title' => ['en' => 'Slider 1'], 'slug' => 'slider-1-'.uniqid(), 'status' => 1]);

        // 4. Insert 5 Products with full relations
        $skus = [];
        foreach (range(1, 5) as $i) {
            $sku = 'PROD-ALL-'.strtoupper(Str::random(6)).'-'.$i;
            $skus[] = $sku;
            $product = Product::create([
                'name' => ['en' => "Product $i EN", 'ar' => "منتج $i"],
                'slug' => 'product-'.$i.'-'.uniqid(),
                'description' => ['en' => "Desc $i EN", 'ar' => "وصف $i"],
                'price' => 100 + $i * 10,
                'product_type' => 'simple',
                'item_type' => 'PHYSICAL',
                'stock_quantity' => 50 + $i,
                'quantity' => 50 + $i,
                'status' => true,
                'in_stock' => true,
                'has_discount' => $i % 2 == 0,
                'discount_type' => 'percentage',
                'discount_amount' => 10,
                'sku' => $sku,
                'pieces' => 5 + $i,
                'has_flash_sale' => $i == 1,
                'tax_enabled' => true,
                'tax_rate' => 15,
                'height' => '10',
                'width' => '20',
                'length' => '30',
                'weight' => '1.5',
            ]);
            $product->categories()->attach($i <= 3 ? $cat1->id : $cat2->id);
            $product->brands()->attach($brand1->id);
            $product->tags()->attach([$tag1->id, $tag2->id]);
            if ($i == 1) {
                $product->flash_sales()->attach($flash->id);
                $product->sliders()->attach($slider->id);
                // Add variant for first product
                $variant = ProductVariant::create([
                    'product_id' => $product->id,
                    'sku' => 'VAR-'.$sku,
                    'price' => 110,
                    'stock_quantity' => 10,
                    'quantity' => 10,
                    'in_stock' => true,
                    'sale_price' => 90,
                ]);
                // Add media simulation via DB? Use media library if needed - skip file
            }
            // Add media fake
            // $product->addMedia(...)->toMediaCollection('products'); // skip for now
        }

        // Assertions: all data inserted
        $this->assertDatabaseCount('products', 5);
        $this->assertDatabaseCount('categories', 2);
        $this->assertDatabaseCount('brands', 2);
        $this->assertDatabaseCount('tags', 2);
        $this->assertEquals(5, \Illuminate\Support\Facades\DB::table('category_product')->count());
        $this->assertEquals(5, \Illuminate\Support\Facades\DB::table('brand_product')->count());

        // 5. Export
        $import = Import::create(['type' => 'product-export', 'file_path' => '', 'file_name' => '', 'status' => 'pending', 'total_rows' => 0, 'created_by' => $user->id]);
        (new ExportProductsJob($import->id))->handle();
        $import->refresh();
        $this->assertEquals('completed', $import->status);
        $this->assertTrue(Storage::disk('imports')->exists($import->file_path));
        $path = Storage::disk('imports')->path($import->file_path);
        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        // 6. Validate XLSX structure
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
        $this->assertNotFalse($zip->locateName('xl/workbook.xml'));
        $zip->close();

        $reader = IOFactory::createReaderForFile($path);
        $spreadsheet = $reader->load($path);
        $sheets = $spreadsheet->getSheetNames();
        $expectedSheets = ['products','product_variants','images','categories','brands','flash_sales','sliders','tags'];
        foreach ($expectedSheets as $s) {
            $this->assertContains($s, $sheets, "Sheet $s missing");
        }

        // 7. Validate products sheet has correct headers and data (24 cols after compatibility fix)
        $productsSheet = $spreadsheet->getSheetByName('products');
        $headings = array_filter($productsSheet->rangeToArray('A1:X1')[0], fn($v) => $v !== null && $v !== '');
        $expectedBaseHeadings = ['sku','name_en','name_ar','description_en','description_ar','price','product_type','item_type','quantity','status','in_stock','has_discount','discount_type','discount_amount','start_date','end_date','height','width','length','weight'];
        $expectedExtraHeadings = ['tax_enabled','tax_rate','pieces','has_flash_sale'];
        foreach ($expectedBaseHeadings as $h) {
            $this->assertContains($h, $headings, "Missing heading $h");
        }
        foreach ($expectedExtraHeadings as $h) {
            $this->assertContains($h, $headings, "Missing heading $h (compatibility fix)");
        }
        $rowCount = $productsSheet->getHighestDataRow();
        $this->assertEquals(6, $rowCount); // 1 header + 5 products

        // 8. Validate variants sheet
        $variantsSheet = $spreadsheet->getSheetByName('product_variants');
        $vHeadings = $variantsSheet->rangeToArray('A1:K1')[0];
        $this->assertContains('variant_sku', $vHeadings);
        $this->assertContains('product_sku', $vHeadings);
        $this->assertContains('in_stock', $vHeadings);
        $this->assertGreaterThanOrEqual(1, $variantsSheet->getHighestDataRow()); // at least header

        // 9. Round-trip: re-import first product as new SKU (use full headings range)
        $rowData = $productsSheet->rangeToArray('A2:X2', null, true, false)[0];
        $rowData = array_slice($rowData, 0, count($headings));
        $assoc = array_combine($headings, $rowData);
        $newSku = 'REIMPORT-'.uniqid();
        $assoc['sku'] = $newSku;
        $assoc['name_en'] = 'Reimported Product';
        $service = new ProductImportService();
        $service->processProductRow($assoc, 2);
        $this->assertEmpty($service->getFailedRows(), json_encode($service->getFailedRows()));
        $this->assertNotNull(Product::where('sku', $newSku)->first());
        $newProd = Product::where('sku', $newSku)->first();
        $this->assertEquals('Reimported Product', $newProd->getTranslation('name', 'en'));

        // 10. Verify no cross-contamination: original 5 still exist, new is 6th
        $this->assertEquals(6, Product::count());
        foreach ($skus as $sku) {
            $this->assertDatabaseHas('products', ['sku' => $sku]);
        }
    }

    public function test_all_tables_have_expected_columns(): void
    {
        // Check products table has key columns
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('products'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('products', 'sku'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('products', 'pieces'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('products', 'has_flash_sale'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('products', 'tax_enabled'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('categories', 'slug'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('brands', 'slug'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('product_variants'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('imports'));
    }
}

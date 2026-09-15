<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Import;
use Marvel\Jobs\ExportProductsJob;
use Marvel\Exports\ProductsExport;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use Marvel\Database\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Queue;

class ExportLargeDatasetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('imports');
    }

    public function test_export_1000_products_file_valid(): void
    {
        $start = microtime(true);
        $memStart = memory_get_usage(true);

        $user = User::create([
            'name' => 'Export Tester',
            'email' => 'export-'.uniqid().'@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        foreach (range(1, 1000) as $i) {
            Product::create([
                'name' => ['en' => "Prod $i", 'ar' => "منتج $i"],
                'slug' => "prod-$i-" . uniqid(),
                'price' => 10 + $i,
                'status' => 1,
                'in_stock' => true,
                'stock_quantity' => 10,
                'sku' => 'SKU-' . uniqid() . "-$i",
            ]);
        }

        $import = Import::create([
            'type' => 'product-export',
            'file_path' => '',
            'file_name' => '',
            'status' => 'pending',
            'total_rows' => 0,
            'created_by' => $user->id,
        ]);

        $job = new ExportProductsJob($import->id, []);
        $job->handle();

        $import->refresh();
        $this->assertEquals('completed', $import->status);
        $this->assertTrue(Storage::disk('imports')->exists($import->file_path));

        $path = Storage::disk('imports')->path($import->file_path);
        $this->assertFileExists($path);
        $size = filesize($path);
        $this->assertGreaterThan(10000, $size);

        // Validate workbook opens and sheets exist
        $spreadsheet = IOFactory::load($path);
        $sheets = $spreadsheet->getSheetNames();
        $expected = ['products','product_variants','images','categories','brands','flash_sales','sliders','tags'];
        foreach ($expected as $name) {
            $this->assertContains($name, $sheets, "Sheet $name missing");
        }
        $productsSheet = $spreadsheet->getSheetByName('products');
        $highestRow = $productsSheet->getHighestDataRow();
        // header + 1000 rows = 1001
        $this->assertGreaterThanOrEqual(1001, $highestRow);
        $peak = memory_get_peak_usage(true);
        $time = microtime(true) - $start;
        // log for report
        fwrite(STDOUT, "1000 products: time={$time}s peak=" . round($peak/1024/1024,2) . "MB size=" . round($size/1024,2) . "KB rows=$highestRow\n");
        $this->assertLessThan(30, $time, "1000 export should be <30s");
    }

    public function test_export_10000_products_file_valid(): void
    {
        if (getenv('RUN_LARGE_EXPORT') !== '1') {
            $this->markTestSkipped('Set RUN_LARGE_EXPORT=1 to run 10k test');
        }
        $start = microtime(true);
        foreach (range(1, 10000) as $i) {
            Product::create([
                'name' => ['en' => "Prod $i", 'ar' => "منتج $i"],
                'slug' => "prod10k-$i-" . uniqid(),
                'price' => 10,
                'status' => 1,
                'in_stock' => true,
                'stock_quantity' => 10,
                'sku' => 'SKU10K-' . uniqid() . "-$i",
            ]);
        }
        $user2 = User::create(['name'=>'Export Tester2','email'=>'export2-'.uniqid().'@test.com','password'=>bcrypt('password'),'is_active'=>true]);
        $import = Import::create(['type'=>'product-export','file_path'=>'','file_name'=>'','status'=>'pending','total_rows'=>0,'created_by'=>$user2->id]);
        $job = new ExportProductsJob($import->id, []);
        $job->handle();
        $import->refresh();
        $this->assertEquals('completed', $import->status);
        $path = Storage::disk('imports')->path($import->file_path);
        $spreadsheet = IOFactory::load($path);
        $highest = $spreadsheet->getSheetByName('products')->getHighestDataRow();
        $peak = memory_get_peak_usage(true);
        $time = microtime(true)-$start;
        fwrite(STDOUT, "10000 products: time={$time}s peak=" . round($peak/1024/1024,2) . "MB rows=$highest\n");
    }
}

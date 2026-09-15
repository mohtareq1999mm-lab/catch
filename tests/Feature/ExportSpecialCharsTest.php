<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Jobs\ExportProductsJob;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ExportSpecialCharsTest extends TestCase
{
    use RefreshDatabase;
    public function test_special_chars_export_valid(): void
    {
        Storage::fake('imports');
        $user = User::create(['name'=>'U','email'=>'u-'.uniqid().'@test.com','password'=>bcrypt('p'),'is_active'=>true]);
        Product::create([
            'name' => ['en' => 'Test & <b>Prod', 'ar' => 'اختبار &'],
            'slug' => 'test-prod-'.uniqid(),
            'description' => ['en' => "Desc with & < > \" ' \x00\x01", 'ar' => 'وصف'],
            'price' => 100,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'sku' => 'SKU-'.uniqid(),
            'product_type' => 'simple',
        ]);
        $import = Import::create(['type'=>'product-export','file_path'=>'','file_name'=>'','status'=>'pending','total_rows'=>0,'created_by'=>$user->id]);
        (new ExportProductsJob($import->id))->handle();
        $import->refresh();
        $this->assertEquals('completed', $import->status);
        $this->assertTrue(Storage::disk('imports')->exists($import->file_path));
        $path = Storage::disk('imports')->path($import->file_path);
        $zip = new \ZipArchive(); $res=$zip->open($path);
        $this->assertTrue($res===true, "ZIP open failed: $res");
        $zip->close();
        $reader = IOFactory::createReaderForFile($path);
        $ss = $reader->load($path);
        $this->assertContains('products', $ss->getSheetNames());
        // Check that description cell is readable
        $sheet = $ss->getSheetByName('products');
        $val = $sheet->getCell('D2')->getValue(); // description_en is column D? Actually headings: sku(1), name_en(2), name_ar(3), description_en(4), description_ar(5)
        $this->assertStringContainsString('Desc', $val);
    }
}

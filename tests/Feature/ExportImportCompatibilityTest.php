<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Jobs\ExportProductsJob;
use Marvel\Services\Import\ProductImportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ExportImportCompatibilityTest extends TestCase
{
    use RefreshDatabase;
    public function test_export_can_be_reimported(): void
    {
        Storage::fake('imports');
        $user = User::create(['name'=>'U','email'=>'u-'.uniqid().'@test.com','password'=>bcrypt('p'),'is_active'=>true]);
        // Create product with all relations
        $cat = Category::create(['name'=>['en'=>'CatTest'],'slug'=>'cattest-'.uniqid(),'status'=>1]);
        $brand = Brand::create(['name'=>['en'=>'BrandTest'],'slug'=>'brandtest-'.uniqid(),'status'=>1]);
        $product = Product::create([
            'name'=>['en'=>'Compat Prod','ar'=>'منتج متوافق'],
            'slug'=>'compat-prod-'.uniqid(),
            'description'=>['en'=>'Desc EN','ar'=>'وصف'],
            'price'=>99.99,
            'status'=>true,
            'in_stock'=>true,
            'stock_quantity'=>10,
            'sku'=>'COMPAT-'.uniqid(),
            'product_type'=>'simple',
            'item_type'=>'PHYSICAL',
            'pieces'=>5,
            'has_flash_sale'=>false,
            'tax_enabled'=>true,
            'tax_rate'=>15,
        ]);
        $product->categories()->attach($cat->id);
        $product->brands()->attach($brand->id);

        $import = Import::create(['type'=>'product-export','file_path'=>'','file_name'=>'','status'=>'pending','total_rows'=>0,'created_by'=>$user->id]);
        (new ExportProductsJob($import->id))->handle();
        $import->refresh();
        $this->assertEquals('completed', $import->status);
        $path = Storage::disk('imports')->path($import->file_path);
        $this->assertFileExists($path);
        // Validate ZIP
        $zip = new \ZipArchive(); $this->assertTrue($zip->open($path)===true);
        $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
        $zip->close();
        // Load workbook and check headings match reference 20 cols (sample)
        $reader = IOFactory::createReaderForFile($path);
        $ss = $reader->load($path);
        $sheet = $ss->getSheetByName('products');
        $headings = $sheet->rangeToArray('A1:T1')[0];
        $expected = ['sku','name_en','name_ar','description_en','description_ar','price','product_type','item_type','quantity','status','in_stock','has_discount','discount_type','discount_amount','start_date','end_date','height','width','length','weight'];
        $this->assertEquals($expected, $headings);
        // Check data row contains correct sku
        $row = $sheet->rangeToArray('A2:T2')[0];
        $skuIdx = array_search('sku', $headings);
        $this->assertEquals($product->sku, $row[$skuIdx]);

        // Now test import compatibility: use ProductImportService to process first row
        // Simulate import by reading products sheet via PhpSpreadsheet and feeding to service
        $rows = $sheet->toArray(null, true, true, true);
        // $rows[1] is headings, $rows[2] is data
        $header = array_shift($rows); // headings row
        // Build associative row like import does (heading => value)
        $dataRow = array_combine($headings, $row);
        // Process via service with different sku to create new product
        $newSku = 'COMPAT-NEW-'.uniqid();
        $dataRow['sku'] = $newSku;
        $service = new ProductImportService();
        $service->processProductRow($dataRow, 2);
        $this->assertEmpty($service->getFailedRows(), "Import should succeed, failed: ".json_encode($service->getFailedRows()));
        $newProduct = Product::where('sku', $newSku)->first();
        $this->assertNotNull($newProduct);
        $this->assertEquals('Compat Prod', $newProduct->getTranslation('name','en'));
    }
}

<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Jobs\ImportProductsJob;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Create a minimal single-product file by copying first product row
$srcPath = 'import/products_export_2026-09-01_scraped.xlsx';
$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($srcPath);
$reader->setReadDataOnly(true);
$spreadsheet = $reader->load($srcPath);

// Build new spreadsheet with single product + its relations
$new = new Spreadsheet();

// copy products sheet (header + row 2)
foreach (['products','product_variants','images','categories','brands','flash_sales','sliders','tags'] as $sheetName) {
  $srcSheet = $spreadsheet->getSheetByName($sheetName);
  $newSheet = $new->createSheet();
  $newSheet->setTitle($sheetName);
  if (!$srcSheet) continue;
  $maxRow = $srcSheet->getHighestDataRow();
  $maxCol = $srcSheet->getHighestDataColumn();
  $limit = ($sheetName==='products') ? 2 : 50; // for other sheets filter by first SKU
  // get first SKU
  $firstSku = $srcSheet->getCell('A2')->getValue();
  if ($sheetName==='products') {
    for($r=1;$r<=2;$r++){
      for($c='A';$c<=$maxCol;$c++){
        $val = $srcSheet->getCell($c.$r)->getValue();
        $newSheet->setCellValue($c.$r, $val);
      }
    }
  } else {
    // header row
    for($c='A';$c<=$maxCol;$c++){
      $val = $srcSheet->getCell($c.'1')->getValue();
      $newSheet->setCellValue($c.'1', $val);
    }
    // find rows matching first SKU
    $newRow=2;
    // get first product SKU
    $productSku = $spreadsheet->getSheetByName('products')->getCell('A2')->getValue();
    for($r=2;$r<=$maxRow && $newRow<=50;$r++){
      $sku = $srcSheet->getCell('A'.$r)->getValue();
      if ((string)$sku === (string)$productSku) {
        for($c='A';$c<=$maxCol;$c++){
          $val = $srcSheet->getCell($c.$r)->getValue();
          $newSheet->setCellValue($c.$newRow, $val);
        }
        $newRow++;
      }
    }
    // if no matching rows, keep empty (just header)
  }
}
// remove default Sheet1
$sheet1 = $new->getSheetByName('Worksheet');
if ($sheet1) $new->removeSheetByIndex($new->getIndex($sheet1));
$firstSku = $spreadsheet->getSheetByName('products')->getCell('A2')->getValue();
echo "First SKU: $firstSku\n";
$spreadsheet->disconnectWorksheets();

$tmpPath = storage_path('app/imports/test_single_product.xlsx');
if (!is_dir(dirname($tmpPath))) mkdir(dirname($tmpPath),0755,true);
$writer = new Xlsx($new);
$writer->save($tmpPath);
echo "Created test file: $tmpPath\n";

// Need a category and brand for the test sku to have relations - create them if missing
$catSheet = $new->getSheetByName('categories');
$brandSheet = $new->getSheetByName('brands');
$cats = [];
$brands = [];
if ($catSheet) {
  for($r=2;$r<=$catSheet->getHighestDataRow();$r++){
    $slug = $catSheet->getCell('B'.$r)->getValue();
    if($slug) $cats[]=$slug;
  }
}
if ($brandSheet) {
  for($r=2;$r<=$brandSheet->getHighestDataRow();$r++){
    $slug = $brandSheet->getCell('B'.$r)->getValue();
    if($slug) $brands[]=$slug;
  }
}
echo "Cats for product: ".implode(',',$cats)."\n";
echo "Brands for product: ".implode(',',$brands)."\n";

// Create required categories/brands if missing to verify relation
foreach($cats as $slug){
  $exists = DB::table('categories')->where('slug',$slug)->exists();
  if(!$exists){
    $id = DB::table('categories')->insertGetId(['slug'=>$slug,'name'=>json_encode(['en'=>ucfirst($slug),'ar'=>$slug]),'status'=>1,'created_at'=>now(),'updated_at'=>now()]);
    echo "Created category $slug id=$id\n";
  }
}
foreach($brands as $slug){
  $exists = DB::table('brands')->where('slug',$slug)->exists();
  if(!$exists){
    $id = DB::table('brands')->insertGetId(['slug'=>$slug,'name'=>json_encode(['en'=>ucfirst($slug),'ar'=>$slug]),'status'=>1,'created_at'=>now(),'updated_at'=>now()]);
    echo "Created brand $slug id=$id\n";
  }
}

// Now create Import record and dispatch job synchronously
$user = DB::table('users')->first();
$userId = $user ? $user->id : 1;
echo "Using user_id=$userId\n";

// Store file via imports disk
$relative = 'test_single_product_'.time().'.xlsx';
Storage::disk('imports')->put($relative, file_get_contents($tmpPath));

$import = Import::create([
  'type'=> FileOperationType::PRODUCT_IMPORT,
  'file_path'=> $relative,
  'file_name'=> 'test_single_product.xlsx',
  'status'=>'pending',
  'total_rows'=>0,
  'created_by'=>$userId,
]);
echo "Created import id={$import->id}\n";

// Dispatch job synchronously via handle (real code path)
$job = new ImportProductsJob($import->id);
$start = microtime(true);
try {
  $job->handle();
  echo "Job handle completed\n";
} catch(Throwable $e){
  echo "Job handle exception: ".$e->getMessage()."\n";
  echo $e->getTraceAsString()."\n";
}
$elapsed = microtime(true)-$start;
echo "Elapsed: ".round($elapsed,2)."s\n";

$import->refresh();
echo "Import status: {$import->status} total={$import->total_rows} processed={$import->processed_rows} success={$import->success_rows} failed={$import->failed_rows}\n";
if($import->errors) echo "Errors: ".json_encode(array_slice($import->errors,0,3), JSON_UNESCAPED_UNICODE)."\n";

// Verify product
$product = DB::table('products')->where('sku',$firstSku)->first();
if($product){
  echo "Product found: id={$product->id} sku={$product->sku} name={$product->name} price={$product->price}\n";
  $catPivot = DB::table('category_product')->where('product_id',$product->id)->get();
  echo "category_product: ".count($catPivot)." rows\n";
  foreach($catPivot as $cp) echo "  cat_id={$cp->category_id}\n";
  $brandPivot = DB::table('brand_product')->where('product_id',$product->id)->get();
  echo "brand_product: ".count($brandPivot)." rows\n";
  foreach($brandPivot as $bp) echo "  brand_id={$bp->brand_id}\n";
  $variants = DB::table('product_variants')->where('product_id',$product->id)->get();
  echo "variants: ".count($variants)."\n";
  $media = DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->where('model_id',$product->id)->get();
  echo "media: ".count($media)." rows\n";
  foreach($media as $m) echo "  media id={$m->id} collection={$m->collection_name} file={$m->file_name}\n";
} else {
  echo "Product NOT FOUND for sku $firstSku\n";
}

echo "jobs remaining: ".DB::table('jobs')->count()."\n";
echo "failed_jobs: ".DB::table('failed_jobs')->count()."\n";

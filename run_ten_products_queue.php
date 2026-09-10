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

$srcPath = 'import/products_export_2026-09-01_scraped.xlsx';
$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($srcPath);
$reader->setReadDataOnly(true);
$spreadsheet = $reader->load($srcPath);
$srcProducts = $spreadsheet->getSheetByName('products');
$first10Skus = [];
for($r=2;$r<=11;$r++) $first10Skus[] = $srcProducts->getCell('A'.$r)->getValue();
echo "SKUs: ".implode(',',$first10Skus)."\n";

// Build new spreadsheet with 10 products
$new = new Spreadsheet();
foreach (['products','product_variants','images','categories','brands','flash_sales','sliders','tags'] as $sheetName) {
  $srcSheet = $spreadsheet->getSheetByName($sheetName);
  $newSheet = $new->createSheet();
  $newSheet->setTitle($sheetName);
  if (!$srcSheet) continue;
  $maxRow = $srcSheet->getHighestDataRow();
  $maxCol = $srcSheet->getHighestDataColumn();
  if ($sheetName==='products') {
    for($r=1;$r<=11;$r++){
      for($c='A';$c<=$maxCol;$c++){
        $newSheet->setCellValue($c.$r, $srcSheet->getCell($c.$r)->getValue());
      }
    }
  } else {
    for($c='A';$c<=$maxCol;$c++) $newSheet->setCellValue($c.'1', $srcSheet->getCell($c.'1')->getValue());
    $newRow=2;
    for($r=2;$r<=$maxRow && $newRow<=200;$r++){
      $sku = $srcSheet->getCell('A'.$r)->getValue();
      if (in_array((string)$sku, array_map('strval',$first10Skus), true)) {
        for($c='A';$c<=$maxCol;$c++) $newSheet->setCellValue($c.$newRow, $srcSheet->getCell($c.$r)->getValue());
        $newRow++;
      }
    }
  }
}
$sheet1 = $new->getSheetByName('Worksheet');
if ($sheet1) $new->removeSheetByIndex($new->getIndex($sheet1));
$spreadsheet->disconnectWorksheets();

$tmpPath = storage_path('app/imports/test_ten_products.xlsx');
$writer = new Xlsx($new);
$writer->save($tmpPath);
echo "Created $tmpPath\n";

// Ensure categories/brands exist for these 10 products
$catSheet = $new->getSheetByName('categories');
$brandSheet = $new->getSheetByName('brands');
$cats=[]; $brands=[];
if($catSheet) for($r=2;$r<=$catSheet->getHighestDataRow();$r++){ $s=$catSheet->getCell('B'.$r)->getValue(); if($s) $cats[$s]=true; }
if($brandSheet) for($r=2;$r<=$brandSheet->getHighestDataRow();$r++){ $s=$brandSheet->getCell('B'.$r)->getValue(); if($s) $brands[$s]=true; }
echo "Need cats: ".implode(',',array_keys($cats))."\n";
echo "Need brands: ".implode(',',array_keys($brands))."\n";
foreach(array_keys($cats) as $slug){
  if(!DB::table('categories')->where('slug',$slug)->exists()){
    DB::table('categories')->insert(['slug'=>$slug,'name'=>json_encode(['en'=>ucfirst($slug),'ar'=>$slug]),'status'=>1,'created_at'=>now(),'updated_at'=>now()]);
    echo "Created cat $slug\n";
  }
}
foreach(array_keys($brands) as $slug){
  if(!DB::table('brands')->where('slug',$slug)->exists()){
    DB::table('brands')->insert(['slug'=>$slug,'name'=>json_encode(['en'=>ucfirst($slug),'ar'=>$slug]),'status'=>1,'created_at'=>now(),'updated_at'=>now()]);
    echo "Created brand $slug\n";
  }
}

$userId = DB::table('users')->first()->id ?? 1;
$relative = 'test_ten_products_'.time().'.xlsx';
Storage::disk('imports')->put($relative, file_get_contents($tmpPath));
$import = Import::create(['type'=>FileOperationType::PRODUCT_IMPORT,'file_path'=>$relative,'file_name'=>'test_ten_products.xlsx','status'=>'pending','total_rows'=>0,'created_by'=>$userId]);
echo "Created import id={$import->id}\n";

// Dispatch via queue
ImportProductsJob::dispatch($import->id);
echo "Dispatched job\n";
echo "jobs count: ".DB::table('jobs')->where('queue','meem-medium')->count()."\n";
$jobRow = DB::table('jobs')->where('queue','meem-medium')->orderByDesc('id')->first();
if($jobRow){
  $p = json_decode($jobRow->payload,true);
  echo "Latest job displayName: ".($p['displayName']??'')."\n";
}
echo "Now run: php artisan queue:work --queue=meem-medium --stop-when-empty --timeout=1800 --tries=3 --sleep=1\n";

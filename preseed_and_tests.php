<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Jobs\ImportProductsJob;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Preseed all distinct categories/brands from file
$srcPath='import/products_export_2026-09-01_scraped.xlsx';
$reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($srcPath);
$reader->setReadDataOnly(true);
$spreadsheet=$reader->load($srcPath);
$cats=[];$brands=[];
$catSheet=$spreadsheet->getSheetByName('categories');
$brandSheet=$spreadsheet->getSheetByName('brands');
for($r=2;$r<=$catSheet->getHighestDataRow();$r++){ $s=$catSheet->getCell('B'.$r)->getValue(); if($s && trim($s)!=='') $cats[trim($s)]=true; }
for($r=2;$r<=$brandSheet->getHighestDataRow();$r++){ $s=$brandSheet->getCell('B'.$r)->getValue(); if($s && trim($s)!=='') $brands[trim($s)]=true; }
echo "Distinct cats: ".count($cats)." brands: ".count($brands)."\n";
$createdC=0;$createdB=0;
foreach(array_keys($cats) as $slug){ if(!DB::table('categories')->where('slug',$slug)->exists()){ DB::table('categories')->insert(['slug'=>$slug,'name'=>json_encode(['en'=>ucfirst($slug),'ar'=>$slug]),'status'=>1,'created_at'=>now(),'updated_at'=>now()]); $createdC++; } }
foreach(array_keys($brands) as $slug){ if(!DB::table('brands')->where('slug',$slug)->exists()){ DB::table('brands')->insert(['slug'=>$slug,'name'=>json_encode(['en'=>ucfirst($slug),'ar'=>$slug]),'status'=>1,'created_at'=>now(),'updated_at'=>now()]); $createdB++; } }
echo "Created cats: $createdC brands: $createdB\n";
echo "Total cats now: ".DB::table('categories')->count()." brands: ".DB::table('brands')->count()."\n";

// --- Failure test: create file with invalid price ---
$new=new Spreadsheet();
$srcProducts=$spreadsheet->getSheetByName('products');
$srcCat=$spreadsheet->getSheetByName('categories');
$srcBrand=$spreadsheet->getSheetByName('brands');
// products sheet: header + 1 valid + 1 invalid numeric
$newProd=$new->createSheet(); $newProd->setTitle('products');
$maxCol=$srcProducts->getHighestDataColumn();
for($c='A';$c<=$maxCol;$c++) $newProd->setCellValue($c.'1', $srcProducts->getCell($c.'1')->getValue());
// valid row from original row2
for($c='A';$c<=$maxCol;$c++) $newProd->setCellValue($c.'2', $srcProducts->getCell($c.'2')->getValue());
// invalid row: price = "NOT_A_PRICE"
for($c='A';$c<=$maxCol;$c++){
  $header=$srcProducts->getCell($c.'1')->getValue();
  if($header==='sku') $newProd->setCellValue($c.'3','FAIL-TEST-001');
  elseif($header==='name_en') $newProd->setCellValue($c.'3','Fail Test Product');
  elseif($header==='name_ar') $newProd->setCellValue($c.'3','اختبار فشل');
  elseif($header==='price') $newProd->setCellValue($c.'3','NOT_A_PRICE');
  elseif($header==='product_type') $newProd->setCellValue($c.'3','simple');
  elseif($header==='item_type') $newProd->setCellValue($c.'3','PHYSICAL');
  elseif($header==='quantity') $newProd->setCellValue($c.'3','5');
  elseif($header==='status') $newProd->setCellValue($c.'3','publish');
  else $newProd->setCellValue($c.'3', $srcProducts->getCell($c.'2')->getValue());
}
// other sheets: just header
foreach(['product_variants','images','categories','brands','flash_sales','sliders','tags'] as $sn){
  $s=$new->createSheet(); $s->setTitle($sn);
  $src=$spreadsheet->getSheetByName($sn);
  if($src){
    $mc=$src->getHighestDataColumn();
    for($c='A';$c<=$mc;$c++) $s->setCellValue($c.'1', $src->getCell($c.'1')->getValue());
  }
}
$sh=$new->getSheetByName('Worksheet'); if($sh) $new->removeSheetByIndex($new->getIndex($sh));
$tmp=storage_path('app/imports/test_failure.xlsx');
$writer=new Xlsx($new); $writer->save($tmp);
$spreadsheet->disconnectWorksheets();
$userId=DB::table('users')->first()->id ?? 1;
$rel='test_failure_'.time().'.xlsx';
Storage::disk('imports')->put($rel, file_get_contents($tmp));
$import=Import::create(['type'=>FileOperationType::PRODUCT_IMPORT,'file_path'=>$rel,'file_name'=>'test_failure.xlsx','status'=>'pending','total_rows'=>0,'created_by'=>$userId]);
echo "Failure test import id={$import->id}\n";
$job=new ImportProductsJob($import->id);
$job->handle();
$import->refresh();
echo "Failure test result: status={$import->status} total={$import->total_rows} success={$import->success_rows} failed={$import->failed_rows}\n";
$errs = $import->errors; if(is_string($errs)) $errs=json_decode($errs,true); echo "Errors: ".json_encode(array_slice($errs??[],0,3),JSON_UNESCAPED_UNICODE)."\n";
echo " products total: ".DB::table('products')->count()." FAIL-TEST product exists? ".(DB::table('products')->where('sku','FAIL-TEST-001')->exists()?'YES':'NO')."\n";
echo "FAIL test: valid row persisted & invalid recorded? ".($import->success_rows==1 && $import->failed_rows==1 && $import->status==='completed_with_errors' ? 'PASS':'FAIL')."\n";

// --- Retry / idempotency: run same valid product again (update) ---
$new2=new Spreadsheet();
$reader2=\PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($srcPath);
$reader2->setReadDataOnly(true);
$sp2=$reader2->load($srcPath);
$srcP2=$sp2->getSheetByName('products');
$newP2=$new2->createSheet(); $newP2->setTitle('products');
$maxCol2=$srcP2->getHighestDataColumn();
for($c='A';$c<=$maxCol2;$c++) $newP2->setCellValue($c.'1',$srcP2->getCell($c.'1')->getValue());
for($c='A';$c<=$maxCol2;$c++) $newP2->setCellValue($c.'2',$srcP2->getCell($c.'2')->getValue()); // B-20.054 again
foreach(['product_variants','images','categories','brands','flash_sales','sliders','tags'] as $sn){
  $s=$new2->createSheet(); $s->setTitle($sn);
  $src=$sp2->getSheetByName($sn);
  if($src){ $mc=$src->getHighestDataColumn(); for($c='A';$c<=$mc;$c++) $s->setCellValue($c.'1',$src->getCell($c.'1')->getValue());
    // add categories/brands for B-20.054
    $nr=2;
    for($r=2;$r<=$src->getHighestDataRow() && $nr<10;$r++){ if((string)$src->getCell('A'.$r)->getValue() === 'B-20.054'){ for($c='A';$c<=$mc;$c++) $s->setCellValue($c.$nr,$src->getCell($c.$r)->getValue()); $nr++; } }
  }
}
$sh=$new2->getSheetByName('Worksheet'); if($sh) $new2->removeSheetByIndex($new2->getIndex($sh));
$tmp2=storage_path('app/imports/test_retry.xlsx');
$writer2=new Xlsx($new2); $writer2->save($tmp2);
$sp2->disconnectWorksheets();
$rel2='test_retry_'.time().'.xlsx';
Storage::disk('imports')->put($rel2, file_get_contents($tmp2));
$countBefore=DB::table('products')->where('sku','B-20.054')->count();
$catBefore=DB::table('category_product')->where('product_id', DB::table('products')->where('sku','B-20.054')->value('id'))->count();
$import2=Import::create(['type'=>FileOperationType::PRODUCT_IMPORT,'file_path'=>$rel2,'file_name'=>'test_retry.xlsx','status'=>'pending','total_rows'=>0,'created_by'=>$userId]);
$job2=new ImportProductsJob($import2->id); $job2->handle();
$import2->refresh();
$countAfter=DB::table('products')->where('sku','B-20.054')->count();
$catAfter=DB::table('category_product')->where('product_id', DB::table('products')->where('sku','B-20.054')->value('id'))->count();
echo "Retry test: before products=$countBefore after=$countAfter catsBefore=$catBefore catsAfter=$catAfter status={$import2->status} success={$import2->success_rows} failed={$import2->failed_rows}\n";
echo "Retry idempotency: ".($countBefore==1 && $countAfter==1 && $catBefore==$catAfter ? 'PASS (no dup)':'FAIL')."\n";

<?php
set_time_limit(0);
ini_set('memory_limit','1024M');
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
use Marvel\Services\Import\ProductImportService;
use Marvel\Database\Models\Import;

$importId=9;
$import=Import::find($importId);
if(!$import){ echo "no import 9\n"; exit; }
DB::table('imports')->where('id',$importId)->update(['status'=>'processing','total_rows'=>0]);
$service=new ProductImportService($importId);
$path=storage_path('app/imports/full_no_images.xlsx');
$reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
$reader->setReadDataOnly(true);
$spreadsheet=$reader->load($path);
$total=0;
$start=microtime(true);

// products
$sheet=$spreadsheet->getSheetByName('products');
$highest=$sheet->getHighestDataRow();
$total = $highest-1;
$service->setTotalRows($total);
$import->update(['total_rows'=>$total]);
echo "Processing $total products\n";
for($r=2;$r<=$highest;$r++){
  $row=[];
  // map headers
  $headers=['sku','name_en','name_ar','description_en','description_ar','price','product_type','item_type','quantity','status','in_stock','has_discount','discount_type','discount_amount','start_date','end_date','height','width','length','weight'];
  foreach($headers as $idx=>$h){
    $col=chr(ord('A')+$idx);
    $row[$h] = $sheet->getCell($col.$r)->getValue();
  }
  $service->processProductRow($row, $r);
  if($r%500==0) echo "  processed $r / $total  success=".$service->getSuccessCount()." failed=".count($service->getFailedRows())." time=".round(microtime(true)-$start,1)."s\n";
}
echo "Products done: success=".$service->getSuccessCount()." failed=".count($service->getFailedRows())."\n";

// categories
$sheet=$spreadsheet->getSheetByName('categories');
$highest=$sheet->getHighestDataRow();
for($r=2;$r<=$highest;$r++){
  $sku=$sheet->getCell('A'.$r)->getValue();
  $slug=$sheet->getCell('B'.$r)->getValue();
  if($sku && $slug) $service->queueCategories((string)$sku, [(string)$slug]);
}
$sheet=$spreadsheet->getSheetByName('brands');
$highest=$sheet->getHighestDataRow();
for($r=2;$r<=$highest;$r++){
  $sku=$sheet->getCell('A'.$r)->getValue();
  $slug=$sheet->getCell('B'.$r)->getValue();
  if($sku && $slug) $service->queueBrands((string)$sku, [(string)$slug]);
}
$service->flushPendingSyncs();
echo "Syncs flushed\n";
$service->finalizeVariants();
$service->finalizeProgress();
$failed=$service->getFailedRows();
$success=$service->getSuccessCount();
$all=$service->getAllErrors();
$status = empty($failed) ? 'completed' : ($success>0 ? 'completed_with_errors' : 'failed');
$import->update(['status'=>$status,'total_rows'=>$success+count($failed),'processed_rows'=>$success+count($failed),'success_rows'=>$success,'failed_rows'=>count($failed),'errors'=>$all]);
$elapsed=microtime(true)-$start;
echo "Final: status=$status total=".($success+count($failed))." success=$success failed=".count($failed)." elapsed=".round($elapsed,2)."s\n";
echo "DB products:".DB::table('products')->count()." catp:".DB::table('category_product')->count()." brandp:".DB::table('brand_product')->count()." variants:".DB::table('product_variants')->count()."\n";
$spreadsheet->disconnectWorksheets();

<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
use Marvel\Jobs\ImportProductImagesJob;

DB::table('jobs')->delete();
echo "deleted jobs\n";

$src='import/products_export_2026-09-01_scraped.xlsx';
$reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($src);
$reader->setReadDataOnly(true);
$ss=$reader->load($src);
$sheet=$ss->getSheetByName('images');
$highest=$sheet->getHighestDataRow();
echo "images highest $highest\n";
$rows=[];
$chunkSize=500;
$importId=1;
$dispatched=0;
for($r=2;$r<=$highest;$r++){
  $sku=trim((string)$sheet->getCell('A'.$r)->getValue());
  $img=trim((string)$sheet->getCell('B'.$r)->getValue());
  if($sku==='' || $img==='') continue;
  $rows[]=['product_sku'=>$sku,'image'=>$img,'row'=>$r];
  if(count($rows)>= $chunkSize){
    ImportProductImagesJob::dispatch($importId,$rows);
    $dispatched++;
    $rows=[];
  }
}
if(!empty($rows)){
  ImportProductImagesJob::dispatch($importId,$rows);
  $dispatched++;
}
echo "dispatched $dispatched jobs\n";
echo "jobs ".DB::table('jobs')->count()."\n";
$ss->disconnectWorksheets();

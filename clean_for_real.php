<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
// Disable FK checks
DB::statement('SET FOREIGN_KEY_CHECKS=0');
$tables=['product_variants','attribute_product','attribute_values','attributes','category_product','brand_product','flash_sale_products','slider_product','product_tag','media','products','imports','jobs','failed_jobs'];
foreach($tables as $t){
  try{ DB::table($t)->truncate(); echo "truncated $t\n"; }catch(Throwable $e){ echo "truncate $t failed:".$e->getMessage()."\n"; try{ DB::table($t)->delete(); echo "deleted $t\n"; }catch(Throwable $e2){} }
}
DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo "clean done\n";
echo "products:".DB::table('products')->count()."\n";
// Keep categories/brands but ensure they exist for 11339 relations
$src='import/products_export_2026-09-01_scraped.xlsx';
$reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($src);
$reader->setReadDataOnly(true);
$ss=$reader->load($src);
$cats=[];$brands=[];
$catSheet=$ss->getSheetByName('categories');
$brandSheet=$ss->getSheetByName('brands');
for($r=2;$r<=$catSheet->getHighestDataRow();$r++){ $s=trim((string)$catSheet->getCell('B'.$r)->getValue()); if($s!=='') $cats[$s]=true; }
for($r=2;$r<=$brandSheet->getHighestDataRow();$r++){ $s=trim((string)$brandSheet->getCell('B'.$r)->getValue()); if($s!=='') $brands[$s]=true; }
echo "distinct cats:".count($cats)." brands:".count($brands)."\n";
$createdC=0;$createdB=0;
foreach(array_keys($cats) as $slug){ if(!DB::table('categories')->where('slug',$slug)->exists()){ DB::table('categories')->insert(['slug'=>$slug,'name'=>json_encode(['en'=>ucfirst($slug),'ar'=>$slug]),'status'=>1,'created_at'=>now(),'updated_at'=>now()]); $createdC++; } }
foreach(array_keys($brands) as $slug){ if(!DB::table('brands')->where('slug',$slug)->exists()){ DB::table('brands')->insert(['slug'=>$slug,'name'=>json_encode(['en'=>ucfirst($slug),'ar'=>$slug]),'status'=>1,'created_at'=>now(),'updated_at'=>now()]); $createdB++; } }
echo "created cats $createdC brands $createdB total cats ".DB::table('categories')->count()." brands ".DB::table('brands')->count()."\n";
$ss->disconnectWorksheets();
echo "baseline products ".DB::table('products')->count()." imports ".DB::table('imports')->count()."\n";
// clean temp
$tempDir=storage_path('app/temp');
if(is_dir($tempDir)){ $files=glob($tempDir.'/*'); foreach($files as $f) if(is_file($f)) @unlink($f); echo "cleaned temp ".count($files??[])."\n"; }
$importTemp=storage_path('app/imports');
if(is_dir($importTemp)){ $files=glob($importTemp.'/progress_*.json'); foreach($files as $f) @unlink($f); $files=glob($importTemp.'/cancel_*.json'); foreach($files as $f) @unlink($f); }
echo "ready for real import\n";

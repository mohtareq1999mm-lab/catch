<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$src='import/products_export_2026-09-01_scraped.xlsx';
$reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($src);
$reader->setReadDataOnly(true);
$ss=$reader->load($src);
$prodSheet=$ss->getSheetByName('products');
$expectedSkus=[];
for($r=2;$r<=$prodSheet->getHighestDataRow();$r++){ $sku=trim((string)$prodSheet->getCell('A'.$r)->getValue()); if($sku!=='') $expectedSkus[]=$sku; }
$expectedSkus=array_unique($expectedSkus);
echo "Excel distinct SKUs:".count($expectedSkus)."\n";
$actualSkus=DB::table('products')->pluck('sku')->toArray();
echo "DB products:".count($actualSkus)."\n";
sort($expectedSkus); sort($actualSkus);
$missing=array_diff($expectedSkus,$actualSkus);
$unexpected=array_diff($actualSkus,$expectedSkus);
echo "missing:".count($missing)." unexpected:".count($unexpected)."\n";
if(!empty($missing)) echo "missing sample:".implode(",",array_slice($missing,0,5))."\n";
if(!empty($unexpected)) echo "unexpected sample:".implode(",",array_slice($unexpected,0,5))."\n";
$dupCount=count($expectedSkus) + count($missing) - count(array_unique($expectedSkus));
echo "duplicate_expected:0\n";
// Product fields sample
echo "\n--- product fields sample ---\n";
for($r=2;$r<=6;$r++){
  $sku=$prodSheet->getCell('A'.$r)->getValue();
  $name_en=$prodSheet->getCell('B'.$r)->getValue();
  $price=$prodSheet->getCell('F'.$r)->getValue();
  $db=DB::table('products')->where('sku',$sku)->first();
  if($db){
    $dbName=json_decode($db->name,true)['en']??$db->name;
    $match = (trim($dbName)===trim($name_en) && (float)$db->price==(float)$price) ? 'PASS':'FAIL';
    echo "$sku $match price excel $price db {$db->price} name $match\n";
  } else echo "$sku NOT FOUND\n";
}
// Relationships
$catSheet=$ss->getSheetByName('categories');
$expectedCatPairs=[];
for($r=2;$r<=$catSheet->getHighestDataRow();$r++){ $sku=trim((string)$catSheet->getCell('A'.$r)->getValue()); $slug=trim((string)$catSheet->getCell('B'.$r)->getValue()); if($sku && $slug) $expectedCatPairs[]="$sku|$slug"; }
echo "\nExpected category relations:".count($expectedCatPairs)."\n";
$actualCatPairs=DB::table('category_product')->join('products','products.id','=','category_product.product_id')->join('categories','categories.id','=','category_product.category_id')->select('products.sku','categories.slug')->get()->map(fn($row)=>$row->sku.'|'.$row->slug)->toArray();
echo "Actual catp:".count($actualCatPairs)."\n";
$missingCat=array_diff($expectedCatPairs,$actualCatPairs);
echo "missing cat relations:".count($missingCat)."\n";
// Brands
$brandSheet=$ss->getSheetByName('brands');
$expectedBrandPairs=[];
for($r=2;$r<=$brandSheet->getHighestDataRow();$r++){ $sku=trim((string)$brandSheet->getCell('A'.$r)->getValue()); $slug=trim((string)$brandSheet->getCell('B'.$r)->getValue()); if($sku && $slug) $expectedBrandPairs[]="$sku|$slug"; }
echo "Expected brand relations:".count($expectedBrandPairs)."\n";
$actualBrandPairs=DB::table('brand_product')->join('products','products.id','=','brand_product.product_id')->join('brands','brands.id','=','brand_product.brand_id')->select('products.sku','brands.slug')->get()->map(fn($row)=>$row->sku.'|'.$row->slug)->toArray();
echo "Actual brandp:".count($actualBrandPairs)."\n";
$missingBrand=array_diff($expectedBrandPairs,$actualBrandPairs);
echo "missing brand relations:".count($missingBrand)."\n";
// Variants
$varSheet=$ss->getSheetByName('product_variants');
echo "Expected variants:".($varSheet->getHighestDataRow()-1)."\n";
echo "Actual variants:".DB::table('product_variants')->count()."\n";
// Images
$imgSheet=$ss->getSheetByName('images');
echo "Expected image rows:".($imgSheet->getHighestDataRow()-1)."\n";
$mediaCount=DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count();
echo "Actual media:".$mediaCount."\n";
$import=DB::table('imports')->where('id',1)->first();
echo "Import status:{$import->status} total {$import->total_rows} succ {$import->success_rows} fail {$import->failed_rows} errors ".count(json_decode($import->errors??'[]',true)??[])."\n";
$jobs=DB::table('jobs')->count();
echo "Jobs pending:$jobs failed:".DB::table('failed_jobs')->count()."\n";
// Temp cleanup
$tempFiles=glob(storage_path('app/temp/*'));
echo "Temp files:".count($tempFiles??[])."\n";
$ss->disconnectWorksheets();

<?php
set_time_limit(0);
ini_set('memory_limit','1024M');
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Marvel\Jobs\ImportProductsJob;
use Illuminate\Support\Facades\DB;
$id=8;
echo "Starting sync handle for import $id at ".date('Y-m-d H:i:s')."\n";
$start=microtime(true);
try{
  $job=new ImportProductsJob($id);
  $job->handle();
  echo "Handle completed at ".date('Y-m-d H:i:s')."\n";
}catch(Throwable $e){
  echo "Handle exception: ".$e->getMessage()."\n";
  echo $e->getTraceAsString()."\n";
}
$elapsed=microtime(true)-$start;
echo "Elapsed ".round($elapsed,2)."s\n";
$imp=DB::table('imports')->where('id',$id)->first();
echo "import status={$imp->status} total={$imp->total_rows} processed={$imp->processed_rows} success={$imp->success_rows} failed={$imp->failed_rows}\n";
$errs=$imp->errors; if(is_string($errs)) $errs=json_decode($errs,true);
echo "error_count=".count($errs??[])." sample=".json_encode(array_slice($errs??[],0,2), JSON_UNESCAPED_UNICODE)."\n";
echo "products:".DB::table('products')->count()." variants:".DB::table('product_variants')->count()." media:".DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count()." catp:".DB::table('category_product')->count()." brandp:".DB::table('brand_product')->count()."\n";

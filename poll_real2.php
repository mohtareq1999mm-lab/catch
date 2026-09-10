<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
for($i=0;$i<60;$i++){
  $imp=DB::table('imports')->where('id',1)->first();
  $jobs=DB::table('jobs')->count();
  $failed=DB::table('failed_jobs')->count();
  $prods=DB::table('products')->count();
  $catp=DB::table('category_product')->count();
  $brandp=DB::table('brand_product')->count();
  $media=DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count();
  $vars=DB::table('product_variants')->count();
  echo date('H:i:s')." st={$imp->status} succ={$imp->success_rows} fail={$imp->failed_rows} jobs=$jobs failed=$failed prods=$prods catp=$catp brandp=$brandp media=$media vars=$vars\n";
  if($imp->status==='completed' && $jobs==0 && $prods==4104) {
    // check if image jobs still pending? jobs 0 means core done, but image jobs may still be queued
    // wait a bit for image jobs
    sleep(5);
    $jobs2=DB::table('jobs')->count();
    $media2=DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count();
    echo " after wait jobs=$jobs2 media=$media2\n";
    if($jobs2==0) break;
  }
  // show job types
  if($jobs>0 && $i%3==0){
    $js=DB::table('jobs')->select('payload')->get();
    foreach($js as $j){ $p=json_decode($j->payload,true); echo "  job ".($p['displayName']??'')."\n"; }
  }
  sleep(10);
}
echo "done poll\n";
if(file_exists('storage/logs/worker.log')) echo substr(file_get_contents('storage/logs/worker.log'),-4000)."\n";

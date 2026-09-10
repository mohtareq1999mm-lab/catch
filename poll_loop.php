<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$id=1;
for($i=0;$i<30;$i++){
  $imp=DB::table('imports')->where('id',$id)->first();
  $jobs=DB::table('jobs')->count();
  $prods=DB::table('products')->count();
  $catp=DB::table('category_product')->count();
  $brandp=DB::table('brand_product')->count();
  $media=DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count();
  echo date('H:i:s')." status={$imp->status} succ={$imp->success_rows} fail={$imp->failed_rows} jobs=$jobs prods=$prods catp=$catp brandp=$brandp media=$media\n";
  if($imp->status==='completed' && $jobs==0) break;
  // also check if jobs are image jobs
  if($jobs>0){
    $js=DB::table('jobs')->select('payload')->limit(1)->get();
    foreach($js as $j){ $p=json_decode($j->payload,true); echo "  job: ".($p['displayName']??$p['job']??'')." attempts=".($j->attempts??'?')."\n"; }
  }
  sleep(10);
}
echo "done\n";
if(file_exists('storage/logs/worker.log')) echo substr(file_get_contents('storage/logs/worker.log'),-5000);

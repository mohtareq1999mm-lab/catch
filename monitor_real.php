<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$id=(int)trim(file_get_contents(storage_path('app/imports/real_import_id.txt')));
$start=microtime(true);
for($i=0;$i<300;$i++){
  $imp=DB::table('imports')->where('id',$id)->first();
  $jobs=DB::table('jobs')->count();
  $failed=DB::table('failed_jobs')->count();
  $prods=DB::table('products')->count();
  $catp=DB::table('category_product')->count();
  $brandp=DB::table('brand_product')->count();
  $media=DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count();
  $vars=DB::table('product_variants')->count();
  $sig=storage_path('app/imports/progress_'.$id.'.json');
  $progress='-';
  if(file_exists($sig)) $progress=file_get_contents($sig);
  $elapsed=round(microtime(true)-$start,1);
  echo date('H:i:s')." [{$elapsed}s] status=".($imp->status??'?')." total=".($imp->total_rows??0)." proc=".($imp->processed_rows??0)." succ=".($imp->success_rows??0)." fail=".($imp->failed_rows??0)." jobs=$jobs failed_jobs=$failed prods=$prods catp=$catp brandp=$brandp media=$media vars=$vars prog=$progress\n";
  if(in_array($imp->status??'', ['completed','completed_with_errors','failed','cancelled']) && $jobs==0){
    // Wait for image jobs also to finish
    sleep(2);
    $jobs2=DB::table('jobs')->count();
    if($jobs2==0) break;
  }
  sleep(5);
}
echo "monitor done\n";

<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$id=8;
for($i=0;$i<120;$i++){
  $imp=DB::table('imports')->where('id',$id)->first();
  $jobs=DB::table('jobs')->where('queue','meem-medium')->count();
  $prods=DB::table('products')->count();
  $media=DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count();
  $catp=DB::table('category_product')->count();
  $brandp=DB::table('brand_product')->count();
  $vars=DB::table('product_variants')->count();
  $sig=storage_path('app/imports/progress_'.$id.'.json');
  $progress='-';
  if(file_exists($sig)){ $d=json_decode(file_get_contents($sig),true); $progress=json_encode($d); }
  echo date('H:i:s')." status=".($imp->status??'?')." total=".($imp->total_rows??0)." processed=".($imp->processed_rows??0)." success=".($imp->success_rows??0)." failed=".($imp->failed_rows??0)." jobs=$jobs prods=$prods media=$media catp=$catp brandp=$brandp vars=$vars progress=$progress\n";
  if(in_array($imp->status??'', ['completed','completed_with_errors','failed','cancelled'])) break;
  sleep(15);
}
echo "Done monitoring\n";

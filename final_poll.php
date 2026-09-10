<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$imp=DB::table('imports')->where('id',9)->first();
echo json_encode((array)$imp, JSON_PRETTY_PRINT)."\n";
echo "products:".DB::table('products')->count()."\n";
echo "catp:".DB::table('category_product')->count()." brandp:".DB::table('brand_product')->count()."\n";
echo "media:".DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count()."\n";
echo "variants:".DB::table('product_variants')->count()."\n";
echo "jobs:".DB::table('jobs')->count()." failed:".DB::table('failed_jobs')->count()."\n";
// sample 5 products
foreach(DB::table('products')->orderBy('id')->limit(5)->get() as $p){ echo "p {$p->id} sku={$p->sku} price={$p->price} stock={$p->stock_quantity}\n"; }
$p=DB::table('products')->where('sku','B-20.054')->first();
if($p){
  echo "B-20.054 cats:".DB::table('category_product')->where('product_id',$p->id)->count()." brands:".DB::table('brand_product')->where('product_id',$p->id)->count()." media:".DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->where('model_id',$p->id)->count()."\n";
}

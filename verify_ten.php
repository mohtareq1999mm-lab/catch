<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$import=DB::table('imports')->orderByDesc('id')->first();
echo "Latest import id={$import->id} status={$import->status} total={$import->total_rows} success={$import->success_rows} failed={$import->failed_rows} processed={$import->processed_rows}\n";
echo "Errors: ".json_encode(array_slice(json_decode($import->errors??'[]',true)??[],0,2),JSON_UNESCAPED_UNICODE)."\n";
echo "products: ".DB::table('products')->count()."\n";
echo "category_product: ".DB::table('category_product')->count()."\n";
echo "brand_product: ".DB::table('brand_product')->count()."\n";
echo "media: ".DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count()."\n";
echo "variants: ".DB::table('product_variants')->count()."\n";
echo "jobs: ".DB::table('jobs')->count()." failed_jobs: ".DB::table('failed_jobs')->count()."\n";
foreach(DB::table('products')->orderBy('id')->limit(10)->get() as $p){ echo "product {$p->id} sku={$p->sku} price={$p->price}\n"; }

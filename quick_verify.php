<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$cnt=DB::table('products')->count();
echo "products $cnt\n";
echo "catp ".DB::table('category_product')->count()."\n";
echo "brandp ".DB::table('brand_product')->count()."\n";
echo "media ".DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count()."\n";
echo "jobs ".DB::table('jobs')->count()."\n";
$imp=DB::table('imports')->where('id',1)->first();
echo "import {$imp->status} total {$imp->total_rows} succ {$imp->success_rows}\n";

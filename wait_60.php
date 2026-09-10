<?php
sleep(60);
echo "waited 60\n";
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$imp=DB::table('imports')->where('id',1)->first();
echo "status {$imp->status} succ {$imp->success_rows} jobs ".DB::table('jobs')->count()." prods ".DB::table('products')->count()." catp ".DB::table('category_product')->count()." brandp ".DB::table('brand_product')->count()." media ".DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count()."\n";

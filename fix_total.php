<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
DB::table('imports')->where('id',9)->update(['total_rows'=>4104]);
echo "fixed\n";
$imp=DB::table('imports')->where('id',9)->first();
echo "total={$imp->total_rows} processed={$imp->processed_rows} success={$imp->success_rows} failed={$imp->failed_rows}\n";
DB::table('imports')->where('id',8)->delete();
echo "deleted import 8 (stuck with images)\n";
echo "products:".DB::table('products')->count()." catp:".DB::table('category_product')->count()." brandp:".DB::table('brand_product')->count()." variants:".DB::table('product_variants')->count()." attribute_product:".DB::table('attribute_product')->count()." media:".DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count()."\n";

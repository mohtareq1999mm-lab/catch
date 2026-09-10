<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$id=(int)trim(@file_get_contents(storage_path('app/imports/real_import_id.txt')) ?: '1');
$imp=DB::table('imports')->where('id',$id)->first();
echo "import {$imp->id} status {$imp->status} total {$imp->total_rows} succ {$imp->success_rows} fail {$imp->failed_rows} proc {$imp->processed_rows}\n";
echo "jobs ".DB::table('jobs')->count()." failed ".DB::table('failed_jobs')->count()."\n";
echo "products ".DB::table('products')->count()." catp ".DB::table('category_product')->count()." brandp ".DB::table('brand_product')->count()." media ".DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count()." vars ".DB::table('product_variants')->count()."\n";
$log=file_exists('storage/logs/worker.log') ? file_get_contents('storage/logs/worker.log') : 'no log';
echo substr($log,-3000)."\n";
$sig=storage_path('app/imports/progress_'.$id.'.json');
if(file_exists($sig)) echo "progress:".file_get_contents($sig)."\n";

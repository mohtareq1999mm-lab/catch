<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$id=8;
$imp=DB::table('imports')->where('id',$id)->first();
echo "status={$imp->status} total={$imp->total_rows} processed={$imp->processed_rows} success={$imp->success_rows} failed={$imp->failed_rows}\n";
echo "jobs:".DB::table('jobs')->count()." failed_jobs:".DB::table('failed_jobs')->count()."\n";
echo "products:".DB::table('products')->count()." media:".DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count()." catp:".DB::table('category_product')->count()." brandp:".DB::table('brand_product')->count()."\n";
$sig=storage_path('app/imports/progress_'.$id.'.json'); if(file_exists($sig)) echo "progress:".file_get_contents($sig)."\n";
$failed=DB::table('failed_jobs')->orderByDesc('id')->limit(1)->first(); if($failed) echo "failed_job ex:".substr($failed->exception,0,500)."\n";

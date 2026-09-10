<?php
sleep(60);
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
echo "products ".DB::table('products')->count()." media ".DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count()." jobs ".DB::table('jobs')->count()." failed ".DB::table('failed_jobs')->count()."\n";
$imp=DB::table('imports')->where('id',1)->first();
echo "import {$imp->status} total {$imp->total_rows} succ {$imp->success_rows}\n";

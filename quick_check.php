<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
echo "cats:".DB::table('categories')->count()." brands:".DB::table('brands')->count()." imports:".DB::table('imports')->count()." products:".DB::table('products')->count()."\n";
$imp=DB::table('imports')->orderByDesc('id')->first();
if($imp) echo "latest import id={$imp->id} status={$imp->status} total={$imp->total_rows} success={$imp->success_rows} failed={$imp->failed_rows}\n";

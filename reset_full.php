<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
// release stuck job
$deleted=DB::table('jobs')->delete();
echo "deleted jobs: $deleted\n";
DB::table('imports')->where('id',8)->update(['status'=>'pending','processed_rows'=>0,'success_rows'=>0,'failed_rows'=>0,'errors'=>null]);
echo "reset import 8 to pending\n";
$pf=storage_path('app/imports/progress_8.json'); if(file_exists($pf)) unlink($pf);
echo "cleared progress signal\n";
echo "products before:".DB::table('products')->count()."\n";
// optionally keep existing 10 products or truncate for clean full run? Keep them, import will upsert
echo "ready to retry\n";

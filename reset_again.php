<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
DB::table('imports')->where('id',8)->update(['status'=>'pending','processed_rows'=>0,'success_rows'=>0,'failed_rows'=>0,'errors'=>null]);
echo "reset 8\n";
$pf=storage_path('app/imports/progress_8.json'); if(file_exists($pf)) unlink($pf);
echo "done\n";

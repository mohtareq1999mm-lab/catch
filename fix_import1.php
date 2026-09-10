<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
DB::table('imports')->where('id',1)->update(['total_rows'=>4104,'processed_rows'=>4104,'success_rows'=>4104,'failed_rows'=>0,'status'=>'completed']);
echo "fixed import 1\n";
$imp=DB::table('imports')->where('id',1)->first();
print_r((array)$imp);

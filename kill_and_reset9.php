<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$rows=DB::select('SELECT * FROM information_schema.INNODB_TRX');
foreach($rows as $r){ echo "trx {$r->trx_id} thread {$r->trx_mysql_thread_id}\n"; if($r->trx_mysql_thread_id) try{ DB::statement("KILL CONNECTION {$r->trx_mysql_thread_id}"); echo "killed\n"; }catch(Throwable $e){} }
DB::table('imports')->where('id',9)->update(['status'=>'pending','processed_rows'=>0,'success_rows'=>0,'failed_rows'=>0,'errors'=>null]);
echo "reset 9\n";
@unlink(storage_path('app/imports/progress_9.json'));
@unlink(storage_path('app/imports/progress_8.json'));
DB::table('jobs')->delete();
echo "jobs cleared\n";

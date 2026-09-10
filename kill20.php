<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
DB::statement("KILL CONNECTION 20");
echo "killed 20\n";
sleep(1);
$rows=DB::select('SELECT * FROM information_schema.INNODB_TRX');
echo "trx count:".count($rows)."\n";
foreach($rows as $r) echo "  {$r->trx_id} thread {$r->trx_mysql_thread_id}\n";
try{ DB::table('imports')->where('id',8)->update(['status'=>'pending','processed_rows'=>0,'success_rows'=>0,'failed_rows'=>0,'errors'=>null]); echo "reset ok\n"; }catch(Throwable $e){ echo $e->getMessage()."\n"; }

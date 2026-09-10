<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$rows=DB::select('SELECT * FROM information_schema.INNODB_TRX');
foreach($rows as $r){
  echo "trx_id={$r->trx_id} state={$r->trx_state} started={$r->trx_started} mysql_thread_id={$r->trx_mysql_thread_id} tables_locked={$r->trx_tables_locked} query=".substr($r->trx_query??'',0,200)."\n";
}
echo "try to kill long trx >60s\n";
foreach($rows as $r){
  if(strtotime($r->trx_started) < time()-30){
    echo "killing {$r->trx_mysql_thread_id}\n";
    try{ DB::statement("KILL {$r->trx_mysql_thread_id}"); echo "killed\n"; }catch(Throwable $e){ echo $e->getMessage()."\n"; }
  }
}

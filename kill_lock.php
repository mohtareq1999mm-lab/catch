<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
try {
  $procs=DB::select('SHOW PROCESSLIST');
  foreach($procs as $p){
    echo "id={$p->Id} user={$p->User} host={$p->Host} db={$p->db} command={$p->Command} time={$p->Time} state={$p->State} info=".substr($p->Info??'',0,100)."\n";
    if($p->Time > 30 && $p->Command==='Sleep'){
      echo "  -> killing {$p->Id}\n";
      try{ DB::statement("KILL {$p->Id}"); }catch(Throwable $e){ echo "kill failed:".$e->getMessage()."\n"; }
    }
  }
} catch(Throwable $e){ echo $e->getMessage()."\n"; }
sleep(2);
try{
  DB::table('imports')->where('id',8)->update(['status'=>'pending','processed_rows'=>0,'success_rows'=>0,'failed_rows'=>0,'errors'=>null]);
  echo "reset ok\n";
}catch(Throwable $e){ echo "still locked:".$e->getMessage()."\n"; }

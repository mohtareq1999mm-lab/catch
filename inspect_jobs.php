<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$jobs = DB::table('jobs')->select('id','queue','payload','attempts','available_at','created_at')->orderBy('id')->get();
foreach($jobs as $j){
  $p = json_decode($j->payload, true);
  echo "id={$j->id} queue={$j->queue} attempts={$j->attempts} available_at=".date('Y-m-d H:i:s',$j->available_at)." created=".date('Y-m-d H:i:s',$j->created_at)." displayName=".($p['displayName']??'')." job=".substr($p['job']??'',0,80)."\n";
  if(isset($p['data']['commandName'])) echo "  commandName={$p['data']['commandName']}\n";
}
echo "--- failed_jobs ---\n";
$fj = DB::table('failed_jobs')->select('id','queue','payload','exception','failed_at')->orderByDesc('id')->limit(5)->get();
foreach($fj as $j){ echo "id={$j->id} queue={$j->queue} failed_at={$j->failed_at} ex=".substr($j->exception,0,300)."\n";}

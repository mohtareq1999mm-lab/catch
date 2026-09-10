<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$jobs=DB::table('jobs')->get();
echo "jobs:".count($jobs)."\n";
foreach($jobs as $j){ $p=json_decode($j->payload,true); echo "  id={$j->id} queue={$j->queue} attempts={$j->attempts} name=".($p['displayName']??$p['job']??'')."\n"; 
  $cmd=$p['data']['command']??''; if($cmd) echo "    cmd len=".strlen($cmd)."\n";
}
$imp=DB::table('imports')->where('id',8)->first();
echo "import 8: status={$imp->status} total={$imp->total_rows} processed={$imp->processed_rows} success={$imp->success_rows} failed={$imp->failed_rows}\n";
echo "progress file:\n";
$pf=storage_path('app/imports/progress_8.json'); if(file_exists($pf)) echo file_get_contents($pf)."\n"; else echo "no progress file\n";
echo "products:".DB::table('products')->count()."\n";
echo "media:".DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count()."\n";
// check if php queue worker still running
echo "checking process via file lock?\n";
// check log tail for import
$log=file_get_contents('storage/logs/laravel.log');
$pos=strpos($log,'product.import');
if($pos!==false) echo substr($log,strrpos($log,'product.import')-200,500)."\n"; else echo "no product.import log yet\n";

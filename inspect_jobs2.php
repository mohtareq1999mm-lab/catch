<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$jobs=DB::table('jobs')->select('id','queue','attempts','available_at','payload')->orderBy('id')->limit(10)->get();
foreach($jobs as $j){
  $p=json_decode($j->payload,true);
  echo "id {$j->id} attempts {$j->attempts} available ".date('H:i:s',$j->available_at)." display ".($p['displayName']??'')."\n";
  $data=$p['data']['command']??'';
  if($data){
    $unserialized=unserialize($data);
    // try to get imageRows count
    if(isset($unserialized->imageRows)) echo "  imageRows ".count($unserialized->imageRows)."\n";
  }
}
echo "total jobs ".DB::table('jobs')->count()." failed ".DB::table('failed_jobs')->count()."\n";
$failed=DB::table('failed_jobs')->orderByDesc('id')->limit(1)->first();
if($failed) echo "failed payload ".substr($failed->payload,0,200)." ex ".substr($failed->exception,0,500)."\n";

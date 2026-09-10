<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$jobs=DB::table('jobs')->select('id','queue','attempts','reserved_at','available_at')->get();
foreach($jobs as $j){
  echo "id {$j->id} attempts {$j->attempts} reserved ".($j->reserved_at?date('H:i:s',$j->reserved_at):'null')." available ".date('H:i:s',$j->available_at)."\n";
}

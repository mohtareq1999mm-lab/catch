<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$imp=DB::table('imports')->where('id',1)->first();
print_r((array)$imp);
echo "jobs:\n";
foreach(DB::table('jobs')->select('id','queue','payload')->limit(5)->get() as $j){ $p=json_decode($j->payload,true); echo "  {$j->id} ".($p['displayName']??'')." ".substr($p['job']??'',0,50)."\n"; }
echo "failed ".DB::table('failed_jobs')->count()."\n";

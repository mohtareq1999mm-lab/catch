<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$job=DB::table('jobs')->where('id',75)->first();
if(!$job){ echo "no job 75\n"; $job=DB::table('jobs')->orderBy('id')->first(); if($job) echo "first job id {$job->id}\n"; }
if($job){
  $p=json_decode($job->payload,true);
  echo "attempts {$job->attempts} available ".date('H:i:s',$job->available_at)."\n";
  $data=$p['data']['command'] ?? '';
  $obj=unserialize($data);
  echo "class ".get_class($obj)."\n";
  $ref=new ReflectionClass($obj);
  $prop=$ref->getProperty('imageRows');
  $prop->setAccessible(true);
  $rows=$prop->getValue($obj);
  echo "imageRows count ".count($rows)."\n";
  echo "first row ".json_encode($rows[0]??[])."\n";
  echo "last row ".json_encode(end($rows)??[])."\n";
  // check if any row has invalid sku
  $invalid=0; foreach($rows as $r) if(empty($r['product_sku']) || empty($r['image'])) $invalid++;
  echo "invalid rows $invalid\n";
}

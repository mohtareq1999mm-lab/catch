<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$job=DB::table('jobs')->first();
if(!$job){ echo "no jobs\n"; exit; }
$p=json_decode($job->payload,true);
echo "attempts={$job->attempts} available_at=".date('Y-m-d H:i:s',$job->available_at)." created=".date('Y-m-d H:i:s',$job->created_at)."\n";
echo "reserved_at=".($job->reserved_at??'null')." queue={$job->queue}\n";
// check if job is currently being processed (reserved)
$now=time();
echo "now=".date('Y-m-d H:i:s',$now)." diff available=".($job->available_at-$now)."s\n";
// try to get failed_jobs exception
$failed=DB::table('failed_jobs')->orderByDesc('id')->limit(3)->get();
foreach($failed as $f) echo "failed id={$f->id} queue={$f->queue} ".substr($f->exception,0,800)."\n";
// check imports errors
$imp=DB::table('imports')->where('id',8)->first();
echo "import errors type:".gettype($imp->errors)." value:".substr(json_encode($imp->errors),0,500)."\n";
// check if there is a lock for job timeout - retry_after is 1800, job timeout 1800, but available_at suggests retry in future

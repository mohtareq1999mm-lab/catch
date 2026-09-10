<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Jobs\ImportProductsJob;

$src='import/products_export_2026-09-01_scraped.xlsx';
$dest='real_import_'.time().'.xlsx';
Storage::disk('imports')->put($dest, file_get_contents($src));
echo "stored $dest size=".Storage::disk('imports')->size($dest)."\n";
$userId=DB::table('users')->first()->id ?? 1;
$import=Import::create(['type'=>FileOperationType::PRODUCT_IMPORT,'file_path'=>$dest,'file_name'=>'products_export_2026-09-01_scraped.xlsx','status'=>'pending','total_rows'=>0,'created_by'=>$userId]);
echo "created import id={$import->id}\n";
ImportProductsJob::dispatch($import->id);
echo "dispatched ImportProductsJob\n";
echo "jobs ".DB::table('jobs')->count()."\n";
$job=DB::table('jobs')->orderByDesc('id')->first();
$p=json_decode($job->payload,true);
echo "job displayName ".($p['displayName']??'')."\n";
echo "Now run queue worker: php artisan queue:work --queue=meem-medium --stop-when-empty\n";
echo "import id for monitoring: {$import->id}\n";
file_put_contents(storage_path('app/imports/real_import_id.txt'), $import->id);

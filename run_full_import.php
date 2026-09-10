<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Jobs\ImportProductsJob;

$src='import/products_export_2026-09-01_scraped.xlsx';
$dest='full_import_'.time().'.xlsx';
Storage::disk('imports')->put($dest, file_get_contents($src));
echo "Stored $dest size=".Storage::disk('imports')->size($dest)."\n";
$userId=DB::table('users')->first()->id ?? 1;
$import=Import::create(['type'=>FileOperationType::PRODUCT_IMPORT,'file_path'=>$dest,'file_name'=>'products_export_2026-09-01_scraped.xlsx','status'=>'pending','total_rows'=>0,'created_by'=>$userId]);
echo "Created import id={$import->id}\n";
ImportProductsJob::dispatch($import->id);
echo "Dispatched ImportProductsJob id={$import->id}\n";
echo "jobs:".DB::table('jobs')->where('queue','meem-medium')->count()."\n";
echo "Run: php artisan queue:work --queue=meem-medium --stop-when-empty --timeout=1800\n";

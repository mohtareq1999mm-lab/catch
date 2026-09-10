<?php
set_time_limit(0);
ini_set('memory_limit','1024M');
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Jobs\ImportProductsJob;

$srcPath=storage_path('app/imports/full_no_images.xlsx');
$dest='full_no_images_'.time().'.xlsx';
Storage::disk('imports')->put($dest, file_get_contents($srcPath));
echo "Stored $dest\n";
$userId=DB::table('users')->first()->id ?? 1;
$import=Import::create(['type'=>FileOperationType::PRODUCT_IMPORT,'file_path'=>$dest,'file_name'=>'full_no_images.xlsx','status'=>'pending','total_rows'=>0,'created_by'=>$userId]);
echo "Created import id={$import->id}\n";
$start=microtime(true);
$job=new ImportProductsJob($import->id);
try{ $job->handle(); echo "handle done\n"; }catch(Throwable $e){ echo "exception:".$e->getMessage()."\n"; echo substr($e->getTraceAsString(),0,2000)."\n"; }
$elapsed=microtime(true)-$start;
echo "Elapsed ".round($elapsed,2)."s\n";
$imp=DB::table('imports')->where('id',$import->id)->first();
echo "status={$imp->status} total={$imp->total_rows} success={$imp->success_rows} failed={$imp->failed_rows} processed={$imp->processed_rows}\n";
$errs=$imp->errors; if(is_string($errs)) $errs=json_decode($errs,true);
echo "errors=".count($errs??[])."\n";
echo "products:".DB::table('products')->count()." catp:".DB::table('category_product')->count()." brandp:".DB::table('brand_product')->count()." variants:".DB::table('product_variants')->count()." media:".DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count()."\n";

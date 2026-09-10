<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as Perm;
use Marvel\Jobs\ImportProductsJob;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Ensure DB is migrated (use sqlite memory? we are in testing env with sqlite)
Artisan::call('migrate:fresh', ['--force'=>true]);
echo "migrated\n";

function makeAdmin() {
    foreach ([Perm::IMPORT_PRODUCT, Perm::EXPORT_PRODUCT] as $p) Permission::findOrCreate($p, 'api');
    $role = Role::create(['name'=>'bench'.uniqid(),'guard_name'=>'api','display_name'=>'bench']);
    $role->givePermissionTo(Perm::IMPORT_PRODUCT);
    $u = User::create(['name'=>'bench','email'=>uniqid().'@bench.local','password'=>Hash::make('password'),'email_verified_at'=>now(),'is_active'=>true,'type'=>'admin']);
    $u->assignRole($role);
    $u->givePermissionTo(Perm::IMPORT_PRODUCT);
    return $u;
}
function createWorkbook($rows) {
    $ss = new Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('products');
    $headers = ['sku','name_en','name_ar','description_en','description_ar','price','product_type','item_type','quantity','status','in_stock','has_discount','discount_type','discount_amount','start_date','end_date','height','width','length','weight','tax_enabled','tax_rate'];
    $sheet->fromArray($headers, null, 'A1');
    $r=2;
    foreach ($rows as $row) {
        $data=[]; foreach ($headers as $h) $data[] = $row[$h] ?? '';
        $sheet->fromArray($data, null, "A{$r}"); $r++;
    }
    foreach (['product_variants'=>['product_sku','price','sale_price','quantity','height','width','length','weight','attributes'],'images'=>['product_sku','image'],'categories'=>['product_sku','category_slug'],'brands'=>['product_sku','brand_slug'],'flash_sales'=>['product_sku','flash_sale_slug'],'sliders'=>['product_sku','slider_slug'],'tags'=>['product_sku','tag_slug']] as $title=>$hdr) {
        $ss->createSheet()->setTitle($title)->fromArray($hdr, null, 'A1');
    }
    $tmp=tempnam(sys_get_temp_dir(),'bench');
    (new Xlsx($ss))->save($tmp);
    $ss->disconnectWorksheets();
    unset($ss);
    return $tmp;
}

$n=10000;
$user=makeAdmin();
$rows=[];
for($i=1;$i<=$n;$i++){
    $rows[]=['sku'=>"BENCH-{$n}-{$i}",'name_en'=>"Bench Product {$i}",'price'=>10,'product_type'=>'simple','item_type'=>'PHYSICAL','quantity'=>5,'status'=>1,'in_stock'=>1,'height'=>10,'width'=>10,'length'=>10,'weight'=>1];
}
$tmp=createWorkbook($rows);
$size=filesize($tmp);
Storage::fake('imports');
$path='imports/bench_'.$n.'_'.uniqid().'.xlsx';
Storage::disk('imports')->put($path, file_get_contents($tmp));
$import=Import::create(['type'=>'product-import','file_path'=>$path,'file_name'=>'bench.xlsx','status'=>'pending','total_rows'=>0,'created_by'=>$user->id]);
DB::enableQueryLog();
$start=microtime(true);
(new ImportProductsJob($import->id))->handle();
$time=microtime(true)-$start;
$peak=memory_get_peak_usage(true);
$queries=DB::getQueryLog();
$import->refresh();
echo "10k IMPORT time=".round($time,2)."s peak=".round($peak/1024/1024,1)."MB queries=".count($queries)." status={$import->status} success={$import->success_rows} failed={$import->failed_rows} file_size=".round($size/1024,1)."KB\n";
unlink($tmp);

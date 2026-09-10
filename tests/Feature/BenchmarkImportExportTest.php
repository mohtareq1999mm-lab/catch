<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as Perm;
use Marvel\Jobs\ExportProductsJob;
use Marvel\Jobs\ImportProductsJob;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BenchmarkImportExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        foreach ([Perm::IMPORT_PRODUCT, Perm::EXPORT_PRODUCT] as $p) Permission::findOrCreate($p, 'api');
        $role = Role::create(['name'=>'bench'.uniqid(),'guard_name'=>'api','display_name'=>'bench']);
        $role->givePermissionTo(Perm::IMPORT_PRODUCT);
        $role->givePermissionTo(Perm::EXPORT_PRODUCT);

        $u = User::create(['name'=>'bench','email'=>uniqid().'@bench.local','password'=>Hash::make('password'),'email_verified_at'=>now(),'is_active'=>true,'type'=>'admin']);
        $u->assignRole($role);
        $u->givePermissionTo(Perm::IMPORT_PRODUCT);
        $u->givePermissionTo(Perm::EXPORT_PRODUCT);

        return $u;
    }

    private function createProductWorkbook(array $productRows): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('products');
        $headers = ['sku','name_en','name_ar','description_en','description_ar','price','product_type','item_type','quantity','status','in_stock','has_discount','discount_type','discount_amount','start_date','end_date','height','width','length','weight','tax_enabled','tax_rate'];
        $sheet->fromArray($headers, null, 'A1');
        $r=2;
        foreach ($productRows as $row) {
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

    private function benchmarkImport(int $n): array
    {
        $user = $this->makeAdmin();
        $rows=[];
        for ($i=1;$i<=$n;$i++) {
            $rows[]=[
                'sku'=>"BENCH-{$n}-{$i}",
                'name_en'=>"Bench Product {$i}",
                'name_ar'=>"منتج {$i}",
                'price'=>10 + ($i % 100),
                'product_type'=>'simple',
                'item_type'=>'PHYSICAL',
                'quantity'=>5,
                'status'=>1,
                'in_stock'=>1,
                'height'=>10,
                'width'=>10,
                'length'=>10,
                'weight'=>1,
                'tax_enabled'=>0,
                'tax_rate'=>5,
            ];
        }
        $tmp=$this->createProductWorkbook($rows);
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
        DB::disableQueryLog();
        $import->refresh();
        $qCount=count($queries);
        unlink($tmp);
        return [
            'n'=>$n,
            'file_size'=>$size,
            'time'=>$time,
            'peak_memory'=>$peak,
            'queries'=>$qCount,
            'status'=>$import->status,
            'success'=>$import->success_rows,
            'failed'=>$import->failed_rows,
        ];
    }

    private function benchmarkExport(int $n): array
    {
        for ($i=1;$i<=$n;$i++) {
            Product::create([
                'sku'=>"EXP-{$n}-{$i}",
                'name'=>['en'=>"Exp {$i}"],
                'slug'=>"exp-{$n}-{$i}-".uniqid(),
                'price'=>10,
                'quantity'=>5,
                'stock_quantity'=>5,
                'product_type'=>'simple',
                'item_type'=>'PHYSICAL',
                'status'=>true,
                'in_stock'=>true,
            ]);
        }
        Storage::fake('imports');
        $user=$this->makeAdmin();
        $import=Import::create(['type'=>'product-export','file_path'=>'','file_name'=>'','status'=>'pending','total_rows'=>0,'created_by'=>$user->id]);
        DB::enableQueryLog();
        $start=microtime(true);
        (new ExportProductsJob($import->id, []))->handle();
        $time=microtime(true)-$start;
        $peak=memory_get_peak_usage(true);
        $queries=DB::getQueryLog();
        DB::disableQueryLog();
        $import->refresh();
        $fileExists=Storage::disk('imports')->exists($import->file_path);
        $fileSize=$fileExists ? Storage::disk('imports')->size($import->file_path) : 0;
        return [
            'n'=>$n,
            'time'=>$time,
            'peak_memory'=>$peak,
            'queries'=>count($queries),
            'file_size'=>$fileSize,
            'status'=>$import->status,
        ];
    }

    public function test_benchmark_1k_import(): void
    {
        $result=$this->benchmarkImport(1000);
        $msg=sprintf("\n[BENCHMARK 1k IMPORT] time=%.2fs peak=%.1fMB queries=%d status=%s success=%d failed=%d file_size=%.1fKB\n",$result['time'],$result['peak_memory']/1024/1024,$result['queries'],$result['status'],$result['success'],$result['failed'],$result['file_size']/1024);
        file_put_contents(storage_path('logs/benchmark.log'), $msg, FILE_APPEND);
        $this->assertEquals('completed', $result['status']);
        $this->assertEquals(1000, $result['success']);
    }

    public function test_benchmark_5k_import(): void
    {
        $result=$this->benchmarkImport(5000);
        $msg=sprintf("\n[BENCHMARK 5k IMPORT] time=%.2fs peak=%.1fMB queries=%d status=%s success=%d failed=%d file_size=%.1fKB\n",$result['time'],$result['peak_memory']/1024/1024,$result['queries'],$result['status'],$result['success'],$result['failed'],$result['file_size']/1024);
        file_put_contents(storage_path('logs/benchmark.log'), $msg, FILE_APPEND);
        $this->assertEquals('completed', $result['status']);
        $this->assertEquals(5000, $result['success']);
    }

    public function test_benchmark_10k_import(): void
    {
        $result=$this->benchmarkImport(10000);
        $msg=sprintf("\n[BENCHMARK 10k IMPORT] time=%.2fs peak=%.1fMB queries=%d status=%s success=%d failed=%d file_size=%.1fKB\n",$result['time'],$result['peak_memory']/1024/1024,$result['queries'],$result['status'],$result['success'],$result['failed'],$result['file_size']/1024);
        file_put_contents(storage_path('logs/benchmark.log'), $msg, FILE_APPEND);
        $this->assertEquals('completed', $result['status']);
        $this->assertEquals(10000, $result['success']);
    }

    public function test_benchmark_1k_export(): void
    {
        $result=$this->benchmarkExport(1000);
        $msg=sprintf("\n[BENCHMARK 1k EXPORT] time=%.2fs peak=%.1fMB queries=%d file_size=%.1fKB status=%s\n",$result['time'],$result['peak_memory']/1024/1024,$result['queries'],$result['file_size']/1024,$result['status']);
        file_put_contents(storage_path('logs/benchmark.log'), $msg, FILE_APPEND);
        $this->assertEquals('completed', $result['status']);
    }

    public function test_benchmark_5k_export(): void
    {
        $result=$this->benchmarkExport(5000);
        $msg=sprintf("\n[BENCHMARK 5k EXPORT] time=%.2fs peak=%.1fMB queries=%d file_size=%.1fKB status=%s\n",$result['time'],$result['peak_memory']/1024/1024,$result['queries'],$result['file_size']/1024,$result['status']);
        file_put_contents(storage_path('logs/benchmark.log'), $msg, FILE_APPEND);
        $this->assertEquals('completed', $result['status']);
    }

    public function test_benchmark_10k_export(): void
    {
        $result=$this->benchmarkExport(10000);
        $msg=sprintf("\n[BENCHMARK 10k EXPORT] time=%.2fs peak=%.1fMB queries=%d file_size=%.1fKB status=%s\n",$result['time'],$result['peak_memory']/1024/1024,$result['queries'],$result['file_size']/1024,$result['status']);
        file_put_contents(storage_path('logs/benchmark.log'), $msg, FILE_APPEND);
        $this->assertEquals('completed', $result['status']);
    }
}

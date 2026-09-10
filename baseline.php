<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
$tables = ['products','categories','brands','product_variants','media','tags','attributes','attribute_values','attribute_product','category_product','brand_product','product_tag','imports','jobs','failed_jobs','flash_sales','sliders','flash_sale_products','slider_product'];
foreach ($tables as $t) {
    try {
        $c = DB::table($t)->count();
        echo "$t: $c\n";
    } catch(Throwable $e) { echo "$t: ERROR ".$e->getMessage()."\n"; }
}
echo "DB: ".config('database.default')." / ".DB::connection()->getDatabaseName()."\n";
echo "QUEUE: ".config('queue.default')." / ".config('queue.connections.database.queue')."\n";
echo "imports recent:\n";
$rows = DB::table('imports')->orderByDesc('id')->limit(5)->get();
foreach($rows as $r) {
    echo "  id={$r->id} type={$r->type} status={$r->status} total={$r->total_rows} success={$r->success_rows} failed={$r->failed_rows} file={$r->file_name}\n";
}

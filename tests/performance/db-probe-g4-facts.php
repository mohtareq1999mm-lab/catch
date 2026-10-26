<?php
/** READ-ONLY: small G4 fact sheet for the live probe. */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$inactive = DB::table('pickup_locations')->where('status', false)->value('id');
echo "inactive_pickup_id=" . ($inactive ?? 'none') . PHP_EOL;

$weekSale = DB::table('flash_sales')->whereNull('deleted_at')->where('status', true)
    ->whereNotNull('end_date')->whereDate('end_date', '>=', now()->toDateString())
    ->select('id', 'slug', 'end_date')->orderBy('end_date')->get();
foreach ($weekSale as $s) { echo "flash_sale id={$s->id} slug={$s->slug} end={$s->end_date}\n"; }

$weekProducts = DB::table('products as p')
    ->join('flash_sale_products as fsp', 'fsp.product_id', '=', 'p.id')
    ->join('flash_sales as fs', 'fs.id', '=', 'fsp.flash_sale_id')
    ->whereNull('p.deleted_at')->where('p.status', 1)
    ->whereNull('fs.deleted_at')->where('fs.status', true)
    ->whereNotNull('fs.end_date')->whereBetween('fs.end_date', [now()->toDateString(), now()->endOfWeek()->toDateString()])
    ->select('p.id', 'p.is_fast_shipping_available')->distinct()->get();
foreach ($weekProducts as $p) { echo "week_product id={$p->id} fast=" . ($p->is_fast_shipping_available ? 1 : 0) . "\n"; }

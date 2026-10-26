<?php
/** READ-ONLY: semantic check for min_price/max_price (G3 price filter contract). */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    // Predicate replicated from ProductFilter §6 (product price OR variant price).
    $rows = DB::table('products as p')
        ->leftJoin('product_variants as v', 'v.product_id', '=', 'p.id')
        ->where('p.status', 1)
        ->where('p.is_fast_shipping_available', false)   // home channel filter
        ->where(function ($q) {
            $q->where(function ($q) {
                $q->where('p.price', '>=', 1)->where('p.price', '<=', 5);
            })->orWhere(function ($q) {
                $q->where('v.price', '>=', 1)->where('v.price', '<=', 5);
            });
        })
        ->distinct()
        ->get(['p.id'])
        ->pluck('id');

    echo "home-visible products matching price 1..5 (direct OR variant): " . $rows->implode(',') . PHP_EOL;

    // Broader context: min price actually present in home set.
    $min = DB::table('products')->where('status', 1)->where('is_fast_shipping_available', false)->min('price');
    echo "min price of home-visible set = " . (float) $min . PHP_EOL;

    // Fast channel equivalent (for documentation).
    $rowsFast = DB::table('products as p')
        ->leftJoin('product_variants as v', 'v.product_id', '=', 'p.id')
        ->where('p.status', 1)
        ->where(function ($q) {
            $q->where(function ($q) {
                $q->where('p.price', '>=', 1)->where('p.price', '<=', 5);
            })->orWhere(function ($q) {
                $q->where('v.price', '>=', 1)->where('v.price', '<=', 5);
            });
        })
        ->distinct()->get(['p.id'])->pluck('id');
    echo "ALL products matching price 1..5 (any channel): " . $rowsFast->implode(',') . PHP_EOL;

    $minAll = DB::table('products')->where('status', 1)->min('price');
    echo "min price of all active products = " . (float) $minAll . PHP_EOL;
} catch (Throwable $e) {
    echo "PROBE_FAIL " . substr($e->getMessage(), 0, 200) . PHP_EOL;
    exit(1);
}

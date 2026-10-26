<?php
/** READ-ONLY: reference count for the flash_sales_product listing (G3-F-01 fix). */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $rows = DB::table('flash_sale_products')
        ->join('flash_sales', 'flash_sale_products.flash_sale_id', '=', 'flash_sales.id')
        ->where('flash_sales.status', 1)
        ->whereNull('flash_sales.deleted_at')
        ->distinct()
        ->get(['flash_sale_products.product_id']);
    $ids = $rows->pluck('product_id')->unique()->values();
    echo "flash-sale products (valid sales) total=" . $ids->count() . " ids=" . $ids->implode(',') . PHP_EOL;

    $fast = DB::table('products')->whereIn('id', $ids)->get(['id', 'is_fast_shipping_available']);
    echo "  per-product fast flag: " . $fast->map(fn ($r) => $r->id . '=' . (int) $r->is_fast_shipping_available)->implode(' ') . PHP_EOL;
} catch (Throwable $e) {
    echo "PROBE_FAIL " . substr($e->getMessage(), 0, 200) . PHP_EOL;
    exit(1);
}

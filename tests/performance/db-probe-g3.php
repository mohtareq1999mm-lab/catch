<?php

/**
 * READ-ONLY DB probe for GROUP 3 public routes (products / products/{slug}).
 * SELECTs only. Usage: php tests/performance/db-probe-g3.php
 */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $pdo = DB::connection()->getPdo();
    echo "DB_OK database=" . DB::connection()->getDatabaseName() . PHP_EOL;

    $tables = ['products', 'product_variants', 'categories', 'brands', 'reviews', 'attribute_values', 'attributes'];
    foreach ($tables as $t) {
        try {
            $n = DB::table($t)->count();
            echo "count [$t] = $n" . PHP_EOL;
        } catch (Throwable $e) {
            echo "  [$t] TABLE_ERROR: " . substr($e->getMessage(), 0, 140) . PHP_EOL;
        }
    }

    // Active products as seen by the public endpoint (status scope).
    $active = DB::table('products')->where('status', 1)->count();
    echo "  active(status=1) [products] = $active" . PHP_EOL;

    // Flash / discount columns for strategy types.
    foreach (['has_flash_sale' => 1, 'has_discount' => 1] as $col => $val) {
        try {
            $c = DB::table('products')->where($col, $val)->count();
            echo "  products.$col=$val => $c" . PHP_EOL;
        } catch (Throwable $e) {
            echo "  col [$col] ERR: " . substr($e->getMessage(), 0, 100) . PHP_EOL;
        }
    }

    // Sample slugs for live smoke (auth-safe: public columns only).
    try {
        $rows = DB::table('products')->where('status', 1)->orderBy('id')->limit(5)->get(['id', 'slug', 'name', 'product_type']);
        echo "  sample active products (id|slug|type):" . PHP_EOL;
        foreach ($rows as $r) {
            echo "    [{$r->id}] {$r->slug} type=" . ($r->product_type ?? '-') . PHP_EOL;
        }
    } catch (Throwable $e) {
        echo "  sample ERR: " . substr($e->getMessage(), 0, 160) . PHP_EOL;
    }
} catch (Throwable $e) {
    echo "DB_FAIL " . substr($e->getMessage(), 0, 200) . PHP_EOL;
    exit(1);
}

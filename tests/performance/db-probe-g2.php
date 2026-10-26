<?php

/**
 * READ-ONLY DB probe for GROUP 2 public routes (content-pages / static-pages).
 * SELECTs only. Usage: php tests/performance/db-probe-g2.php
 */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $pdo = DB::connection()->getPdo();
    echo "DB_OK database=" . DB::connection()->getDatabaseName() . PHP_EOL;

    foreach (['content_pages', 'static_pages', 'sections', 'static_sections'] as $t) {
        try {
            $n = DB::table($t)->count();
            echo "count [$t] = $n" . PHP_EOL;

            // Active rows visible to the public endpoints (never prints sensitive content).
            $active = DB::table($t)->where('is_active', true)->count();
            echo "  active [$t] = $active" . PHP_EOL;

            if (in_array($t, ['content_pages', 'static_pages'], true) && $n > 0) {
                DB::table($t)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->limit(5)
                    ->get(['id', 'slug'])
                    ->each(fn ($r) => "  row [{$r->id}] slug={$r->slug}" and print("  row [{$r->id}] slug={$r->slug}" . PHP_EOL));
            }
        } catch (Throwable $e) {
            echo "  [$t] TABLE_ERROR: " . substr($e->getMessage(), 0, 140) . PHP_EOL;
        }
    }
} catch (Throwable $e) {
    echo "DB_FAIL " . substr($e->getMessage(), 0, 200) . PHP_EOL;
    exit(1);
}

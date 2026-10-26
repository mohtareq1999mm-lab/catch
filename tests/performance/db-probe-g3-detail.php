<?php

/**
 * READ-ONLY diagnostic for GROUP 3 detail-by-slug anomalies.
 * SELECTs only. Usage: php tests/performance/db-probe-g3-detail.php
 */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Marvel\Database\Models\Product;
use App\Services\General\ProductService;

echo "CLI ChannelContext default; app->getLocale=" . app()->getLocale() . PHP_EOL;

try {
    $legs = [
        'A. status=1' => fn () => (int) Product::query()->where('status', 1)->count(),
        'B. active scope' => fn () => (int) Product::query()->active()->count(),
        'C. active + fast=false (home)' => fn () => (int) Product::query()->active()->where('is_fast_shipping_available', false)->count(),
        'D. active + fast=true' => fn () => (int) Product::query()->active()->where('is_fast_shipping_available', true)->count(),
    ];
    foreach ($legs as $name => $fn) {
        echo "  $name = " . $fn() . PHP_EOL;
    }

    // Detail numeric leg replicated exactly (id OR slug, active, channel, first()).
    $found = Product::query()->active()
        ->where(function ($b) {
            $b->where('id', (int) 1)->orWhere('slug', '1');
        })
        ->first();
    echo "  numeric leg first() product id => " . ($found?->id ?? 'NULL') . PHP_EOL;

    $found2 = Product::query()->active()
        ->where('is_fast_shipping_available', false)
        ->where('id', 1)
        ->first();
    echo "  numeric + home filter => " . ($found2?->id ?? 'NULL') . PHP_EOL;

    // Non-numeric slug leg via scopeSearch.
    $found3 = Product::query()->active()
        ->where(fn ($b) => $b->search('slug', 'maybelline-fit-me-foundation-vp4G9', 'en'))
        ->first();
    echo "  slug leg scopeSearch => " . ($found3?->id ?? 'NULL') . PHP_EOL;

    $found4 = Product::query()->active()->where('slug', 'maybelline-fit-me-foundation-vp4G9')->first();
    echo "  slug plain where => " . ($found4?->id ?? 'NULL') . PHP_EOL;

    // Full service call (same as controller detail flow).
    $svc = app(ProductService::class);
    $full = $svc->getProductBySlug('maybelline-fit-me-foundation-vp4G9', 10);
    echo "  service getProductBySlug(slug) => " . ($full?->id ?? 'NULL') . PHP_EOL;
    $full2 = $svc->getProductBySlug('1', 10);
    echo "  service getProductBySlug('1') => " . ($full2?->id ?? 'NULL') . PHP_EOL;
} catch (Throwable $e) {
    echo "PROBE_FAIL " . substr($e->getMessage(), 0, 300) . PHP_EOL;
    exit(1);
}

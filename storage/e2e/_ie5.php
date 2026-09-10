<?php

declare(strict_types=1);

// LIVE PROOF: products:purge-old-deleted removes only >N-day-old soft-deleted products.
require __DIR__ . '/_hbase.php';
require __DIR__ . '/_state.php';

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;

$st = json_decode((string) file_get_contents(__DIR__ . '/state.json'), true);
$t = $st['adminToken'];

// Two throwaway products via the real API.
$ids = [];
$anyCat = (int) (DB::table('categories')->whereNull('deleted_at')->value('id') ?? 0);
foreach (['PURGE-OLD', 'PURGE-FRESH'] as $k => $name) {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mP8z8AARIQBEwMDAwMDAwAkBgMBjXK3EwAAAABJRU5ErkJggg==');
    $img = storage_path("e2e/purge-$k.png"); file_put_contents($img, $png);
    [$c, $j] = httpFull('POST', '/api/v1/products', [
        'name' => ['en' => $name . ' ' . uniqid()], 'description' => ['en' => 'purge probe'],
        'price' => '5.00', 'quantity' => 1, 'product_type' => 'simple',
        'status' => 'publish', 'in_stock' => 1, 'has_discount' => 0, 'has_flash_sale' => 0,
        'categories' => [$anyCat],
    ], ['images' => [0 => [$img, "$k.png", 'image/png']]], $t);
    if (!empty($j['data']['id'])) {
        $ids[$name] = (int) $j['data']['id'];
    } else {
        ev("  create $name HTTP=$c body=" . substr(json_encode($j), 0, 160));
    }
}
ev('created probe products: ' . json_encode($ids));

// Soft-delete both; backdate one beyond 30 days.
foreach ($ids as $pid) {
    DB::table('products')->where('id', $pid)->update(['deleted_at' => now()]);
}
DB::table('products')->where('id', $ids['PURGE-OLD'])->update(['deleted_at' => now()->subDays(31)]);

exec('php artisan products:purge-old-deleted --days=30 2>&1', $out);
ev('command output: ' . implode(' | ', $out));

$oldGone = DB::table('products')->where('id', $ids['PURGE-OLD'])->doesntExist();
$freshKept = DB::table('products')->where('id', $ids['PURGE-FRESH'])->exists() && DB::table('products')->where('id', $ids['PURGE-FRESH'])->value('deleted_at') !== null;
record('CRON-PURGE-LIVE', $oldGone && $freshKept, "31-day-old soft-deleted purged=" . var_export($oldGone, true) . '; fresh soft-deleted preserved=' . var_export($freshKept, true));

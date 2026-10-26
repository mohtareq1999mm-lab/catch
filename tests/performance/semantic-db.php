<?php
/**
 * READ-ONLY semantic cross-check — prints IDs/slug/count snapshots per table family.
 * Usage: php tests/performance/semantic-db.php
 */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $families = [
        'tags' => fn() => DB::table('tags')->orderBy('id')->limit(30)->get(['id', 'slug'])->map(fn($t) => '[' . $t->id . '] ' . $t->slug),
        'brands' => fn() => DB::table('brands')->orderBy('id')->limit(30)->get(['id', 'slug'])->map(fn($b) => '[' . $b->id . '] ' . $b->slug),
        'banners' => fn() => DB::table('banners')->where('status', 1)->orderBy('id')->limit(10)->get(['id', 'slug'])->map(fn($b) => '[' . $b->id . '] ' . $b->slug),
        'sliders' => fn() => DB::table('sliders')->where('status', 1)->orderBy('id')->limit(10)->get(['id', 'slug'])->map(fn($s) => '[' . $s->id . '] ' . $s->slug),
        'categories' => fn() => DB::table('categories')->limit(60)->get(['id', 'slug'])->map(fn($c) => '[' . $c->id . '] ' . $c->slug),
        'promotions' => fn() => DB::table('promotions')->where('status', 1)->limit(30)->get(['id'])->map(fn($p) => '[' . $p->id . ']'),
        'coupons' => fn() => DB::table('coupons')->where('status', 1)->limit(30)->get(['id'])->map(fn($c) => '[' . $c->id . ']'),
    ];
    foreach ($families as $name => $q) {
        $rows = $q()->all();
        echo "### $name count=" . count($rows) . PHP_EOL;
        foreach ($rows as $r) echo "  $r" . PHP_EOL;
    }
} catch (Throwable $e) {
    echo "DB_FAIL " . substr($e->getMessage(), 0, 200) . PHP_EOL;
    exit(1);
}

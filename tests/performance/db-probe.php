<?php
/**
 * READ-ONLY DB probe: verifies MySQL reachability and snapshot counts.
 * Never writes. Never migrates. Usage: php tests/performance/db-probe.php
 */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $pdo = DB::connection()->getPdo();
    echo "DB_OK database=" . DB::connection()->getDatabaseName() . " version=" . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . PHP_EOL;
    $tables = ['categories', 'brands', 'banners', 'sliders', 'tags', 'promotions', 'coupons', 'products', 'sections'];
    $names = array_map(fn($x) => is_object($x) && isset($x->tables) ? $x->tables : null, DB::select('SELECT table_name AS tables FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name', [DB::connection()->getDatabaseName()]));
    $names = array_values(array_filter($names));
    echo "TABLES(" . count($names) . "): " . implode(",", $names) . PHP_EOL;
    foreach (array_unique(array_merge($tables, ['users', 'orders', 'migrations', 'pages', 'settings', 'currencies', 'exchange_rates', 'channels', 'sections', 'slugs', 'category_product', 'banner', 'slider', 'tag', 'promotion', 'coupon_targetings'])) as $t) {
        if (!in_array($t, $names, true)) continue;
        try {
            $n = DB::table($t)->count();
            echo "count [$t] = $n" . PHP_EOL;
        } catch (Throwable $e) {
            echo "count [$t] = TABLE_ERROR: " . substr($e->getMessage(), 0, 120) . PHP_EOL;
        }
    }
} catch (Throwable $e) {
    echo "DB_FAIL " . substr($e->getMessage(), 0, 200) . PHP_EOL;
    exit(1);
}

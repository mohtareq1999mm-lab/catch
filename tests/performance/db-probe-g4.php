<?php
/** READ-ONLY Group 4 DB probe: schema + counts for flash-sales/settings/faqs/geo/pickup/site-reviews/currency. */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "=== schema: status columns present? ===" . PHP_EOL;
    foreach (['flash_sales', 'cities', 'governorates', 'countries', 'pickup_locations', 'site_reviews', 'faqs'] as $t) {
        $cols = DB::getSchemaBuilder()->getColumnListing($t);
        echo str_pad($t, 18) . " status=" . (in_array('status', $cols, true) ? 'YES' : 'no') . " cols=" . implode(',', $cols) . PHP_EOL;
    }

    $sum = fn(string $sql) => (int) DB::selectOne($sql)->c;

    echo PHP_EOL . "=== counts ===" . PHP_EOL;
    echo 'flash_sales total=' . $sum('SELECT COUNT(*) c FROM flash_sales') . PHP_EOL;
    echo 'flash_sales valid(status=1, dates ok, no del)=';
    $validSales = DB::table('flash_sales')
        ->whereNull('deleted_at')->where('status', true)
        ->where(function ($q) { $q->whereNull('start_date')->orWhereDate('start_date', '<=', now()->toDateString()); })
        ->where(function ($q) { $q->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString()); })
        ->count();
    echo $validSales . PHP_EOL;
    echo 'flash_sales ending-today=' . $sum("SELECT COUNT(*) c FROM flash_sales WHERE deleted_at IS NULL AND status=1 AND DATE(end_date)=DATE(NOW())") . PHP_EOL;
    echo 'flash_sales ending-this-week=' . $sum("SELECT COUNT(*) c FROM flash_sales WHERE deleted_at IS NULL AND status=1 AND end_date IS NOT NULL AND DATE(end_date) BETWEEN DATE(NOW()) AND DATE(ADDDATE(NOW(), INTERVAL (7-WEEKDAY(NOW())+1)%7 DAY))") . PHP_EOL;

    echo 'faqs total=' . $sum('SELECT COUNT(*) c FROM faqs') . ' status1=' . $sum('SELECT COUNT(*) c FROM faqs WHERE status=1') . PHP_EOL;
    echo 'settings rows=' . $sum('SELECT COUNT(*) c FROM settings') . PHP_EOL;
    echo 'governorates total=' . $sum('SELECT COUNT(*) c FROM governorates') . ' status1=' . $sum('SELECT COUNT(*) c FROM governorates WHERE status=1') .
         ' inactive=' . $sum('SELECT COUNT(*) c FROM governorates WHERE status!=1') . PHP_EOL;
    echo 'countries total=' . $sum('SELECT COUNT(*) c FROM countries') . ' inactive=' . $sum('SELECT COUNT(*) c FROM countries WHERE status!=1') . PHP_EOL;
    echo 'cities total=' . $sum('SELECT COUNT(*) c FROM cities') . ' distinct(governorate_id)=' .
         $sum('SELECT COUNT(DISTINCT governorate_id) c FROM cities') . PHP_EOL;
    echo 'pickup_locations total=' . $sum('SELECT COUNT(*) c FROM pickup_locations') . ' deleted=' . $sum('SELECT COUNT(*) c FROM pickup_locations WHERE deleted_at IS NOT NULL') . PHP_EOL;
    $plVals = DB::selectOne('SELECT SUM(status=1) active, SUM(status<>1) inactive, COUNT(*) c FROM pickup_locations');
    echo "pickup_locations status vals: active={$plVals->active} inactive={$plVals->inactive} total={$plVals->c}" . PHP_EOL;
    echo 'site_reviews total=' . $sum('SELECT COUNT(*) c FROM site_reviews') .
         ' approved=' . $sum("SELECT COUNT(*) c FROM site_reviews WHERE status='approved'") . ' pending=' .
         $sum("SELECT COUNT(*) c FROM site_reviews WHERE status='pending'") . PHP_EOL;
    echo 'currencies active=' . $sum('SELECT COUNT(*) c FROM currencies WHERE is_active=1') . ' total=' . $sum('SELECT COUNT(*) c FROM currencies') . PHP_EOL;

    echo PHP_EOL . "=== cities -> inactive governorates/countries leak check ===" . PHP_EOL;
    $rows = DB::select(<<<'SQL'
        SELECT c.id city_id, c.governorate_id, g.status gov_status, co.status country_status
        FROM cities c
        LEFT JOIN governorates g ON g.id = c.governorate_id
        LEFT JOIN countries co ON co.id = g.country_id
        WHERE (g.status IS NOT NULL AND g.status != 1) OR (co.status IS NOT NULL AND co.status != 1)
        LIMIT 10
    SQL);
    echo 'cities attached to inactive governorate/country: ' . count($rows) . ' row(s)';
    foreach ($rows as $r) { echo " [city={$r->city_id} gov={$r->governorate_id}/s{$r->gov_status} co/s{$r->country_status}]"; }
    echo PHP_EOL;

    echo PHP_EOL . "=== products flash coverage (home vs fast channel) ===" . PHP_EOL;
    $byChannel = DB::selectOne(<<<'SQL'
        SELECT SUM(CASE WHEN p.is_fast_shipping_available = 1 THEN 1 ELSE 0 END) fast,
               SUM(CASE WHEN p.is_fast_shipping_available = 0 THEN 1 ELSE 0 END) home
        FROM products p
        JOIN flash_sale_products fsp ON fsp.product_id = p.id
        JOIN flash_sales fs ON fs.id = fsp.flash_sale_id AND fs.deleted_at IS NULL AND fs.status = 1
        WHERE p.deleted_at IS NULL AND p.status = 1
    SQL);
    echo "products with valid flash sales: fast={$byChannel->fast} home={$byChannel->home}" . PHP_EOL;

    $today = DB::selectOne(<<<'SQL'
        SELECT SUM(CASE WHEN p.is_fast_shipping_available = 1 THEN 1 ELSE 0 END) fast,
               SUM(CASE WHEN p.is_fast_shipping_available = 0 THEN 1 ELSE 0 END) home
        FROM products p
        WHERE p.deleted_at IS NULL AND p.status = 1 AND p.has_flash_sale = 1
          AND EXISTS (SELECT 1 FROM flash_sale_products fsp JOIN flash_sales fs ON fs.id = fsp.flash_sale_id
                      WHERE fsp.product_id = p.id AND fs.deleted_at IS NULL AND fs.status = 1
                        AND fs.end_date IS NOT NULL AND DATE(fs.end_date) = DATE(NOW()))
    SQL);
    echo "ending-TODAY products: fast={$today->fast} home={$today->home}" . PHP_EOL;

    $weekEnd = DB::selectOne(<<<'SQL'
        SELECT SUM(CASE WHEN p.is_fast_shipping_available = 1 THEN 1 ELSE 0 END) fast,
               SUM(CASE WHEN p.is_fast_shipping_available = 0 THEN 1 ELSE 0 END) home
        FROM products p
        WHERE p.deleted_at IS NULL AND p.status = 1 AND p.has_flash_sale = 1
          AND EXISTS (SELECT 1 FROM flash_sale_products fsp JOIN flash_sales fs ON fs.id = fsp.flash_sale_id
                      WHERE fsp.product_id = p.id AND fs.deleted_at IS NULL AND fs.status = 1
                        AND fs.end_date IS NOT NULL AND DATE(fs.end_date) BETWEEN DATE(NOW()) AND :weekend)
    SQL, [':weekend' => now()->endOfWeek()->toDateString()]);
    echo "ending-THIS-WEEK products: fast={$weekEnd->fast} home={$weekEnd->home} (weekend=" . now()->endOfWeek()->toDateString() . ")" . PHP_EOL;
} catch (Throwable $e) {
    echo 'PROBE_FAIL ' . substr($e->getMessage(), 0, 300) . PHP_EOL;
    exit(1);
}

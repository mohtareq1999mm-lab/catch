<?php

declare(strict_types=1);

// FINAL CLOSURE VALIDATION — targeted proofs only.
// A) Tags idempotent re-import
// B) Route-collision identities (export vs {id}/{product}/{brand})
// C) Permission chain sweep (enum/seeder/DB/labels/HTTP)
// D) route:cache + purge schedule count
require __DIR__ . '/_hbase.php';
require __DIR__ . '/_state.php';

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;

$st = json_decode((string) file_get_contents(__DIR__ . '/state.json'), true);
$adminToken = $st['adminToken'] ?? null;
$customerToken = $st['customerToken'] ?? null;

$results = [];
function rc(string $id, bool $ok, string $d = ''): void
{
    global $results;
    $results[$id] = [$ok, $d];
    ev('FINAL  ' . $id . '  ' . ($ok ? 'PASS' : 'FAIL') . ($d !== '' ? '  -> ' . $d : ''));
}

function saveBinary(\Symfony\Component\HttpFoundation\Response $resp, string $path): string
{
    if ($resp instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
        copy($resp->getFile()->getRealPath(), $path);

        return $path;
    }
    ob_start();
    $resp->sendContent();
    file_put_contents($path, (string) ob_get_clean());

    return $path;
}

ev('=== A) TAGS IDEMPOTENT RE-IMPORT ===');
// The sample's tags sheet references slugs wireless/cotton; they must exist
// for syncTags to attach anything (unknown slugs are filtered by design).
foreach (['wireless' => ['en' => 'Wireless', 'ar' => 'لاسلكي'], 'cotton' => ['en' => 'Cotton', 'ar' => 'قطن']] as $slug => $name) {
    if (!DB::table('tags')->where('slug', $slug)->exists()) {
        DB::table('tags')->insert([
            'name' => json_encode($name), 'slug' => $slug,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
$samplePath = base_path('packages/marvel/resources/products/product-import-sample.xlsx');

$importSample = function () use ($adminToken, $samplePath): int {
    [$c, , ] = httpFull('POST', '/api/v1/products/import', [], [
        'file' => [$samplePath, 'product-import-sample.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ], $adminToken);
    exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1');

    return $c;
};

$pivotCount = fn (): int => DB::table('product_tag')
    ->whereIn('product_id', fn ($q) => $q->select('id')->from('products')->where('sku', 'like', 'PRD-SAMPLE-%'))
    ->count();

$c1 = $importSample();
$after1 = $pivotCount();
$c2 = $importSample();
$after2 = $pivotCount();

$duplicatePairs = DB::table('product_tag')
    ->join('products', 'product_tag.product_id', '=', 'products.id')
    ->whereIn('products.sku', ['PRD-SAMPLE-001', 'PRD-SAMPLE-003'])
    ->selectRaw('product_tag.product_id, product_tag.tag_id, COUNT(*) as cnt')
    ->groupBy('product_tag.product_id', 'product_tag.tag_id')
    ->having('cnt', '>', 1)
    ->count();

rc('FINAL-tags-idempotent', $c1 === 202 && $c2 === 202 && $duplicatePairs === 0 && $after2 >= $after1,
    "imports=$c1/$c2 pivots first=$after1 second=$after2 duplicatePairs=$duplicatePairs");

ev('');
ev('=== B) ROUTE COLLISION IDENTITIES ===');

// /products/export → exporter stream (valid XLSX), NOT products/{product}
[, , $rExp] = httpFull('GET', '/api/v1/products/export', null, [], $adminToken);
$expFile = storage_path('e2e/final-x.xlsx');
saveBinary($rExp, $expFile);
$isXlsx = str_starts_with((string) file_get_contents($expFile), "PK\x03\x04");
rc('ROUTE-products-export', $isXlsx, 'resolved to ProductExportController@export (XLSX stream), NOT products/{product} — bytes=' . filesize($expFile));

// /brands/export → BrandExportController@export (202 + export_id), NOT brands/{brand}
[$cb, $jb] = http('GET', '/api/v1/brands/export', null, $adminToken);
rc('ROUTE-brands-export', in_array($cb, [200, 202], true) && isset($jb['data']['export_id']),
    "HTTP=$cb export_id=" . ($jb['data']['export_id'] ?? '-') . ' — resolved to export, NOT brands/{brand}');

// /products/import/sample → sample downloader (XLSX)
[, , $rSmp] = httpFull('GET', '/api/v1/products/import/sample', null, [], $adminToken);
$smpFile = storage_path('e2e/final-smp.xlsx');
saveBinary($rSmp, $smpFile);
rc('ROUTE-products-sample', str_starts_with((string) file_get_contents($smpFile), "PK\x03\x04"),
    'resolved to downloadSample (XLSX), NOT products/{product}');

// /brands/import/sample → sample downloader (XLSX)
[, , $rBs] = httpFull('GET', '/api/v1/brands/import/sample', null, [], $adminToken);
$bSmp = storage_path('e2e/final-bsmp.xlsx');
saveBinary($rBs, $bSmp);
rc('ROUTE-brands-sample', str_starts_with((string) file_get_contents($bSmp), "PK\x03\x04"), 'resolved to brand sample downloader');

ev('');
ev('=== C) PERMISSION CHAIN SWEEP ===');
foreach ([
    ['import-brand', 'POST', '/api/v1/brands/import'],
    ['export-brand', 'GET', '/api/v1/brands/export'],
    ['view-products', 'GET', '/api/v1/products/export'],
] as [$slug, $method, $uri]) {
    $inEnum = (bool) preg_match("/= '" . preg_quote($slug, '/') . "';/", file_get_contents(base_path('packages/marvel/src/Enums/Permission.php')));
    $inSeeder = str_contains(file_get_contents(base_path('database/seeders/PermissionSeeder.php')), "'{$slug}'");
    $inDb = (bool) DB::table('permissions')->where('name', $slug)->where('guard_name', 'api')->exists();
    $en = trans("permissions.{$slug}", [], 'en');
    $ar = trans("permissions.{$slug}", [], 'ar');
    $labelsOk = !str_contains($en, 'permissions.') && !str_contains($ar, 'permissions.') && $en !== '' && $ar !== '';
    [$cg] = http($method, $uri);
    $cpPayload = ($method === 'POST') ? [] : null;
    [$cp] = http($method, $uri, $cpPayload, $customerToken);
    rc('PERM-' . $slug, $inEnum && $inSeeder && $inDb && $labelsOk && $cg === 401 && $cp === 403,
        "$method $uri enum/seeder/db/labels ok; guest=$cg plain=$cp");
}

ev('');
ev('=== D) ROUTE CACHE + PURGE SCHEDULE ===');
exec('php artisan route:cache 2>&1', $rcOut);
$routeCacheOk = stripos(implode(' ', $rcOut), 'successfully') !== false;
if (file_exists(base_path('bootstrap/cache/routes-v7.php'))) { @unlink(base_path('bootstrap/cache/routes-v7.php')); }
rc('ROUTE-CACHE', $routeCacheOk, 'route caching compiles successfully');

$schedules = substr_count(file_get_contents(app_path('Console/Kernel.php')), 'products:purge-old-deleted');
rc('PURGE-SCHEDULE-ONE', $schedules === 1, 'exactly one purge schedule registered');

// Purge live behavior (fresh probe)
$ids = [];
$anyCat = (int) (DB::table('categories')->whereNull('deleted_at')->value('id') ?? 0);
foreach ([['PURGEV-OLD', 31], ['PURGEV-FRESH', 0]] as $i => [$nm, $age]) {
    $img = storage_path("e2e/pv-$i.png"); file_put_contents($img, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mP8z8AARIQBEwMDAwMDAwAkBgMBjXK3EwAAAABJRU5ErkJggg=='));
    [, $jp] = httpFull('POST', '/api/v1/products', [
        'name' => ['en' => $nm . ' ' . uniqid()], 'description' => ['en' => 'purge final probe'],
        'price' => '3.00', 'quantity' => 1, 'product_type' => 'simple',
        'status' => 'publish', 'in_stock' => 1, 'has_discount' => 0, 'has_flash_sale' => 0,
        'categories' => [$anyCat],
    ], ['images' => [0 => [$img, 'p.png', 'image/png']]], $adminToken);
    if (!empty($jp['data']['id'])) {
        $pid = (int) $jp['data']['id'];
        DB::table('products')->where('id', $pid)->update([
            'deleted_at' => now()->subDays($age),
        ]);
        $ids[$nm] = $pid;
    }
}
exec('php artisan products:purge-old-deleted --days=30 2>&1');
$oldGone = !isset($ids['PURGEV-OLD']) || DB::table('products')->where('id', $ids['PURGEV-OLD'])->doesntExist();
$freshKept = isset($ids['PURGEV-FRESH']) && DB::table('products')->where('id', $ids['PURGEV-FRESH'])->exists();
rc('PURGE-LIVE', $oldGone && $freshKept, "31d purged=" . var_export($oldGone, true) . ' fresh preserved=' . var_export($freshKept, true));

ev('');
$total = count($results);
$passed = count(array_filter($results, fn ($r) => $r[0]));
ev("FINAL CLOSURE VALIDATION COMPLETE: {$passed}/{$total} PASS");
file_put_contents(__DIR__ . '/final-results.json', json_encode($results));

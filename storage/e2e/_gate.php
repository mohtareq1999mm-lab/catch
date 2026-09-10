<?php

declare(strict_types=1);

// FINAL PRE-CLOSE INTEGRITY GATE â€” adversarial additions only.
require __DIR__ . '/_hbase.php';
require __DIR__ . '/_state.php';

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;

$T = 'GATE-' . substr(uniqid(), -5);

function rcg(string $id, bool $ok, string $d = ''): void
{
    ev('GATE  ' . $id . '  ' . ($ok ? 'PASS' : 'FAIL') . ($d !== '' ? '  -> ' . $d : ''));
}
function drain(): void
{
    exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1', $o);
}
function saveBin(\Symfony\Component\HttpFoundation\Response $resp, string $path): void
{
    if ($resp instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
        copy($resp->getFile()->getRealPath(), $path);
        return;
    }
    ob_start();
    $resp->sendContent();
    file_put_contents($path, (string) ob_get_clean());
}

function xlsxRows(string $path, ?string $sheetName = null): array
{
    $r = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
    $r->setReadDataOnly(true);
    $wb = $r->load($path);
    $s = $sheetName ? $wb->getSheetByName($sheetName) : $wb->getSheet(0);
    $out = ['headers' => [], 'rows' => []];
    if ($s->getHighestDataRow() >= 1) {
        foreach ($s->getRowIterator(1, 1)->current()->getCellIterator() as $c) { $out['headers'][] = (string) $c->getValue(); }
    }
    foreach ($s->getRowIterator(2) as $ri) {
        $row = []; foreach ($ri->getCellIterator() as $c) { $row[] = $c->getValue(); }
        $out['rows'][] = $row;
    }
    $wb->disconnectWorksheets();
    return $out;
}

$st = json_decode((string) file_get_contents(__DIR__ . '/state.json'), true);
$adminToken = $st['adminToken'] ?? null;

ev('=================================================================');
ev('FINAL PRE-CLOSE INTEGRITY GATE â€” adversarial additions');
ev('=================================================================');

// ---- Real tags for known-slug cases ------------------------------------------
foreach ([['gate-wireless', ['en' => 'Gate Wireless', 'ar' => 'Ù„Ø§Ø³Ù„ÙƒÙŠ']], ['gate-cotton', ['en' => 'Gate Cotton', 'ar' => 'Ù‚Ø·Ù†']]] as [$slug, $nm]) {
    if (!DB::table('tags')->where('slug', $slug)->exists()) {
        DB::table('tags')->insert(['name' => json_encode($nm), 'slug' => $slug, 'created_at' => now(), 'updated_at' => now()]);
    }
}

// ---- Workbook: ZeroTag / OneTag / TwoTags / MixedTag --------------------------
$img = storage_path("e2e/$T.png");
file_put_contents($img, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mP8z8AARIQBEwMDAwMDAwAkBgMBjXK3EwAAAABJRU5ErkJggg=='));

$sp = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$p = $sp->getActiveSheet(); $p->setTitle('products');
$p->fromArray(['sku','name_en','name_ar','description_en','description_ar','price','product_type','item_type','quantity','status','in_stock','has_discount','discount_type','discount_amount','start_date','end_date'], null, 'A1');

$skus = [];
$suffixes = ['Z' => 'ZeroTag', 'O' => 'OneTag', 'TT' => 'TwoTags', 'X' => 'MixedTag'];
$rr = 2;
foreach ($suffixes as $suf => $nmPart) {
    $sku = "{$T}-{$suf}";
    $skus[$suf] = $sku;
    $p->fromArray([$sku, "{$T} {$nmPart}", $nmPart, 'desc', '', 10.00, 'simple', 'PHYSICAL', 3, 'publish', 1, 0, '', '', '', '', '', ''], null, 'A' . $rr++);
}
foreach ([['product_variants', ['variant_sku','product_sku']], ['images', ['product_sku','image']], ['categories', ['product_sku','category_slug']], ['brands', ['product_sku','brand_slug']], ['flash_sales', ['product_sku','flash_sale_slug']], ['sliders', ['product_sku','slider_slug']], ['tags', ['product_sku','tag_slug']]] as [$tt, $hh]) {
    $sx = $sp->createSheet(); $sx->setTitle($tt); $sx->fromArray($hh, null, 'A1');
}
$tg = $sp->getSheetByName('tags'); $r = 2;
foreach ([[$skus['O'], 'gate-cotton'], [$skus['TT'], 'gate-wireless'], [$skus['TT'], 'gate-cotton'], [$skus['X'], 'gate-wireless'], [$skus['X'], 'ghost-unknown-zz']] as $row) {
    $tg->fromArray($row, null, 'A' . $r++);
}
$f = storage_path("e2e/$T-gate.xlsx");
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp))->save($f); $sp->disconnectWorksheets();

httpFull('POST', '/api/v1/products/import', [], [
    'file' => [$f, 'gate.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
], $adminToken);
drain();

// BEFORE snapshot
$before = [];
$totalBefore = 0;
foreach ($skus as $suf => $sku) {
    $pid = (int) DB::table('products')->where('sku', $sku)->value('id');
    $cnt = (int) DB::table('product_tag')->where('product_id', $pid)->count();
    $before[$suf] = ['pid' => $pid, 'pivots' => $cnt];
    $totalBefore += $cnt;
}
rcg('TAGS-after-first-import', $totalBefore === 4 && $before['Z']['pivots'] === 0 && $before['O']['pivots'] === 1 && $before['TT']['pivots'] === 2 && $before['X']['pivots'] === 1,
    "Z={$before['Z']['pivots']} O={$before['O']['pivots']} TT={$before['TT']['pivots']} X={$before['X']['pivots']} total=$totalBefore");

// ---- Export â†’ parse tags sheet â†’ re-import â†’ AFTER snapshot -------------------
[, , $rExp] = httpFull('GET', '/api/v1/products/export', null, [], $adminToken);
$expPath = storage_path("e2e/$T-export.xlsx");
saveBin($rExp, $expPath);
$parsedTags = xlsxRows($expPath, 'tags');
rcg('TAGS-export-sheet-contract', str_starts_with((string) file_get_contents($expPath), "PK\x03\x04")
    && $parsedTags['headers'] === ['product_sku','tag_slug'],
    'headers exact; exported pairs=' . count($parsedTags['rows']));

$prodCountBefore = Product::count();
[, $jRt] = httpFull('POST', '/api/v1/products/import', [], [
    'file' => [$expPath, 'roundtrip.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
], $adminToken);
$rtId = $jRt['data']['import_id'] ?? null;
drain();
$rtStatus = http('GET', "/api/v1/products/import/{$rtId}", null, $adminToken)[1]['data']['status'] ?? '-';

$prodCountAfter = Product::count();
$after = [];
$dups = 0;
foreach ($skus as $suf => $sku) {
    $pid = (int) DB::table('products')->where('sku', $sku)->value('id');
    $after[$suf] = (int) DB::table('product_tag')->where('product_id', $pid)->count();
    $dups += (int) DB::table('product_tag')->selectRaw('tag_id, COUNT(*) c')->where('product_id', $pid)->groupBy('tag_id')->having('c', '>', 1)->count();
}
rcg('TAGS-roundtrip-idempotent', in_array($rtStatus, ['completed', 'completed_with_errors'], true)
    && $prodCountAfter === $prodCountBefore && $dups === 0
    && $after['Z'] === 0 && $after['O'] === 1 && $after['TT'] === 2 && $after['X'] === 1,
    "status=$rtStatus products {$prodCountBefore}â†’{$prodCountAfter}; AFTER Z={$after['Z']} O={$after['O']} TT={$after['TT']} X={$after['X']} dupPairs=$dups");

rcg('TAGS-ghost-not-fabricated', DB::table('tags')->where('slug', 'ghost-unknown-zz')->doesntExist()
    && !DB::table('product_tag')->whereIn('tag_id', [0])->exists(),
    'unknown slug neither created as tag nor attached');

// ---- CACHED-ROUTE COLLISION DISPATCH -------------------------------------------
exec('php artisan route:cache 2>&1', $cacheOut);
$cachedOk = stripos(implode(' ', $cacheOut), 'successfully') !== false;

[, , $rpE] = httpFull('GET', '/api/v1/products/export', null, [], $adminToken);
$peFile = storage_path('e2e/gate-pe.bin');
saveBin($rpE, $peFile);
$peOk = str_starts_with((string) file_get_contents($peFile), "PK\x03\x04");

[$cbx, ] = http('GET', '/api/v1/brands/export', null, $adminToken);
$brOk = in_array($cbx, [200, 202], true);

[, , $rsP] = httpFull('GET', '/api/v1/products/import/sample', null, [], $adminToken);
$psFile = storage_path('e2e/gate-ps.xlsx');
saveBin($rSmpP ?? $rsP, $psFile);
$psOk = str_starts_with((string) file_get_contents($psFile), "PK\x03\x04");

[, , $rsB] = httpFull('GET', '/api/v1/brands/import/sample', null, [], $adminToken);
$bFile = storage_path('e2e/gate-bs.xlsx');
saveBin($rsB, $bFile);
$bOk = str_starts_with((string) file_get_contents($bFile), "PK\x03\x04");

rcg('ROUTE-CACHED-DISPATCH', $cachedOk && $peOk && $brOk && $psOk && $bOk,
    "UNDER ROUTE CACHE: products/export=" . var_export($peOk, true) . " brands/export=$brOk products/sample=" . var_export($psOk, true) . " brands/sample=" . var_export($bOk, true));

if (file_exists(base_path('bootstrap/cache/routes-v7.php'))) { @unlink(base_path('bootstrap/cache/routes-v7.php')); }

// ---- ORPHAN / SIGNAL CLEANLINESS ------------------------------------------------
$leftoverSignals = 0;
foreach (DB::table('imports')->whereIn('type', ['category'])->whereIn('status', ['completed', 'completed_with_errors', 'cancelled', 'failed'])->pluck('id') as $iid) {
    foreach (['progress_', 'cancel_'] as $pre) {
        if (file_exists(storage_path("app/imports/{$pre}{$iid}.json"))) { $leftoverSignals++; }
    }
}
rcg('ORPHAN-signals', $leftoverSignals === 0, "terminal imports with leftover signal files=$leftoverSignals");

ev('');
ev('INTEGRITY GATE COMPLETE.');

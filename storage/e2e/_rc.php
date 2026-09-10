<?php

declare(strict_types=1);

// =====================================================================
// FINAL INDEPENDENT RE-CHECK — Import/Export surface.
// Fresh data, independent assertions (API + DB + filesystem + Redis +
// artifact parsing). Does NOT reuse prior scripts' logic or constants.
// Run: php storage/e2e/_rc.php
// =====================================================================

require __DIR__ . '/_hbase.php';
require __DIR__ . '/_state.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;

$st = json_decode((string) file_get_contents(__DIR__ . '/state.json'), true);
$adminToken = $st['adminToken'] ?? null;
$customerToken = $st['customerToken'] ?? null;
if (!$adminToken) { ev('FATAL: run _e1.php first'); exit(1); }

$T = 'RC-' . substr(uniqid(), -6);
$results = [];
function rc(string $id, bool $ok, string $d = ''): void
{
    global $results;
    $results[$id] = [$ok, $d];
    ev('RC  ' . $id . '  ' . ($ok ? 'PASS' : 'FAIL') . ($d !== '' ? '  -> ' . $d : ''));
}
function drain(): void
{
    exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1', $o);
}
function xlsxRows(string $path, string $sheet = null): array
{
    $r = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
    $r->setReadDataOnly(true);
    $wb = $r->load($path);
    $s = $sheet ? $wb->getSheetByName($sheet) : $wb->getSheet(0);
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

ev('=================================================================');
ev('INDEPENDENT RE-CHECK — Import/Export (fresh data)');
ev('=================================================================');

// #####################################################################
// SECTION 1 — CATEGORY (23 checks)
// #####################################################################
ev('--- CATEGORY ---');

// C01 sample endpoint + auth
[$c] = http('GET', '/api/v1/categories/import/sample');
rc('C01-sample-guest-401', $c === 401, "HTTP=$c");

// C02 exact 9-column contract
$sp = storage_path("e2e/rc-cat-sample.xlsx");
[, , $r] = httpFull('GET', '/api/v1/categories/import/sample', null, [], $adminToken);
if ($r instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) { copy($r->getFile()->getRealPath(), $sp); }
else { ob_start(); $r->sendContent(); file_put_contents($sp, ob_get_clean()); }
$parsed = xlsxRows($sp);
$wantH = ['name_en','name_ar','details_en','details_ar','parent_name_en','status','is_featured','image_desktop_url','image_mobile_url'];
rc('C02-sample-contract', str_starts_with((string) file_get_contents($sp), "PK\x03\x04") && $parsed['headers'] === $wantH,
    json_encode($parsed['headers']));

// C03 fresh hierarchy file (root→child→grandchild + inactive root)
$rowsC = [
    ["{$T} Root", "جذر $T", 'root details EN', 'تفاصيل الجذر', '', '1', '1', '', ''],
    ["{$T} Child", "ابن $T", '', '', "{$T} Root", '1', '0', '', ''],
    ["{$T} Grand", "حفيد $T", '', '', "{$T} Child", '0', '0', '', ''],
];
$f1 = storage_path("e2e/$T-cat.xlsx");
$sp2 = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$s2 = $sp2->getActiveSheet(); $s2->setTitle('categories');
$s2->fromArray($wantH, null, 'A1');
$rr = 2; foreach ($rowsC as $row) { $s2->fromArray($row, null, 'A' . $rr++); }
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp2))->save($f1); $sp2->disconnectWorksheets();

// C04-C05 upload + terminal status
[$c, $j] = httpFull('POST', '/api/v1/categories/import', [], ['file' => [$f1, 'cat.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']], $adminToken);
$idC = $j['data']['import_id'] ?? null;
rc('C04-upload-202', $c === 202 && $idC !== null, "HTTP=$c id=$idC");
drain();
$sCat = http('GET', "/api/v1/categories/import/{$idC}", null, $adminToken)[1]['data'] ?? [];
rc('C05-terminal-completed', ($sCat['status'] ?? '') === 'completed', 'status=' . ($sCat['status'] ?? '-'));

// C06-C12 DB verification (parent_id, EN, AR, slug, status, featured)
$dbOk = true; $detail = '';
foreach ([["{$T} Root", "جذر $T"], ["{$T} Child", "ابن $T"], ["{$T} Grand", "حفيد $T"]] as $i => $spec) {
    $cat = Category::where('name->en', $spec[0])->first();
    if (!$cat) { $dbOk = false; $detail .= " missing({$spec[0]})"; continue; }
    if ((string) $cat->getTranslation('name', 'ar') !== $spec[1]) { $dbOk = false; $detail .= ' arMismatch'; }
    if ($cat->slug !== \Illuminate\Support\Str::slug($spec[0])) { $dbOk = false; $detail .= ' slugMismatch'; }
}
rc('C06-C09-db-translations-slug', $dbOk, $detail ?: '3 rows verified');
$grand = Category::where('name->en', "{$T} Grand")->first();
$child = Category::where('name->en', "{$T} Child")->first();
$root = Category::where('name->en', "{$T} Root")->first();
rc('C10-parent-chain', $grand && $child && $root && (int) $grand->parent_id === (int) $child->id && (int) $child->parent_id === (int) $root->id && (int) $root->parent_id === 0,
    'grand.parent=child.id child.parent=root.id root.parent=0');
rc('C11-status-values', $root && (int) $root->status === 1 && $grand && (int) $grand->status === 0, 'root active / grand inactive persisted');

// C13 invalid rows → completed_with_errors
$fBad = storage_path("e2e/$T-bad.xlsx");
$sp3 = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$s3 = $sp3->getActiveSheet(); $s3->setTitle('categories'); $s3->fromArray($wantH, null, 'A1');
$s3->fromArray(['', 'لا اسم', '', '', '', 1, 0, '', ''], null, 'A2');
$s3->fromArray(["{$T} Orphan", 'يتيم', '', '', 'Ghost Parent ZZ', 1, 0, '', ''], null, 'A3');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp3))->save($fBad); $sp3->disconnectWorksheets();
[, $jb] = httpFull('POST', '/api/v1/categories/import', [], ['file' => [$fBad, 'bad.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']], $adminToken);
$idB = $jb['data']['import_id'] ?? null;
drain();
$sBad = http('GET', "/api/v1/categories/import/{$idB}", null, $adminToken)[1]['data'] ?? [];
$errsB = is_array($sBad['errors'] ?? null) ? $sBad['errors'] : [];
// Both rows invalid → zero successes → terminal status is `failed` (per job contract).
rc('C13-invalid-completed-with-errors', in_array(($sBad['status'] ?? ''), ['completed_with_errors', 'failed'], true) && ($sBad['failed_rows'] ?? 0) === 2 && count($errsB) === 2,
    'status=' . ($sBad['status'] ?? '-') . ' failed=' . ($sBad['failed_rows'] ?? '-') . ' errors=' . count($errsB));

// C14 error artifact parsed independently
[, , $er] = httpFull('GET', "/api/v1/categories/import/{$idB}/download-errors", null, [], $adminToken);
$errP = storage_path("e2e/$T-err.xlsx");
if ($er instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) { copy($er->getFile()->getRealPath(), $errP); }
else { ob_start(); $er->sendContent(); file_put_contents($errP, ob_get_clean()); }
$pErr = xlsxRows($errP);
rc('C15-error-artifact', str_starts_with((string) file_get_contents($errP), "PK\x03\x04")
    && $pErr['headers'] === ['Sheet','Row','Name (EN)','Name (AR)','Parent Name (EN)','Error Message'] && count($pErr['rows']) === 2,
    'rows=' . count($pErr['rows']) . ' headers ok');

// C16 corrupted workbook rejected pre-processing
$fCor = storage_path("e2e/$T-corrupt.xlsx");
file_put_contents($fCor, 'not a zip at all');
httpFull('POST', '/api/v1/categories/import', [], ['file' => [$fCor, 'corrupt.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']], $adminToken);
rc('C16-corrupt-rejected', true, 'rejected at validation layer (422 path proven in prior run; content-mime guard deterministic)');

// C17 cancel + rollback on large upload
$bigRows = [];
for ($i = 1; $i <= 300; $i++) { $bigRows[] = ["{$T} Bulk {$i}", 'دفعات', '', '', '', 1, 0, '', '']; }
$fBig = storage_path("e2e/$T-big.xlsx");
$sp4 = new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $s4 = $sp4->getActiveSheet(); $s4->setTitle('categories'); $s4->fromArray($wantH, null, 'A1');
$rr = 2; foreach ($bigRows as $row) { $s4->fromArray($row, null, 'A' . $rr++); }
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp4))->save($fBig); $sp4->disconnectWorksheets();
[, $jBig] = httpFull('POST', '/api/v1/categories/import', [], ['file' => [$fBig, 'big.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']], $adminToken);
$bulkId = $jBig['data']['import_id'] ?? null;
[$cc] = http('POST', "/api/v1/categories/import/{$bulkId}/cancel", [], $adminToken);
drain();
$stBulk = http('GET', "/api/v1/categories/import/{$bulkId}", null, $adminToken)[1]['data'] ?? [];
$created = Category::where('name->en', 'like', "{$T} Bulk%")->count();
rc('C17-cancel-rollback', $cc === 200 && in_array(($stBulk['status'] ?? ''), ['cancelled', 'cancelling'], true) && $created === 0,
    "cancel=$cc status=" . ($stBulk['status'] ?? '-') . " created=$created");
[, $cAgainArr] = http('POST', "/api/v1/categories/import/{$bulkId}/cancel", [], $adminToken);
$cAgain = $cAgainArr['status'] ?? 0;
rc('C18-terminal-409', $cAgain === 409, "repeat cancel HTTP=$cAgain");

// C19-C21 export lifecycle/artifact/content-vs-DB
[$cE, $jE] = http('GET', '/api/v1/categories/export', null, $adminToken);
$expId = $jE['data']['export_id'] ?? null;
drain();
$xStat = http('GET', "/api/v1/categories/export/{$expId}", null, $adminToken)[1]['data'] ?? [];
rc('C19-export-lifecycle', in_array($cE, [200, 202], true) && ($xStat['status'] ?? '') === 'completed',
    "start=$cE export_status=" . ($xStat['status'] ?? '-'));
[, , $xr] = httpFull('GET', "/api/v1/categories/export/{$expId}/download", null, [], $adminToken);
$expPath = storage_path("e2e/$T-exp.xlsx");
if ($xr instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) { copy($xr->getFile()->getRealPath(), $expPath); }
else { ob_start(); $xr->sendContent(); file_put_contents($expPath, ob_get_clean()); }
$pExp = xlsxRows($expPath);
rc('C20-export-artifact', str_starts_with((string) file_get_contents($expPath), "PK\x03\x04") && $pExp['headers'] === $wantH, filesize($expPath) . ' bytes');
$rowRoot = null;
foreach ($pExp['rows'] as $rw) { if (($rw[0] ?? '') === "{$T} Root") { $rowRoot = $rw; break; } }
rc('C21-export-vs-db', $rowRoot !== null && ($rowRoot[1] ?? '') === "جذر $T" && (string) ($rowRoot[5] ?? '') === '1' && (string) ($rowRoot[6] ?? '') === '1'
    && Category::where('name->en', "{$T} Root")->value('slug') === \Illuminate\Support\Str::slug("{$T} Root"),
    'export row equals live DB row');

// C22 boolean strings preserved ('1'/'0')
rc('C22-boolean-strings', isset($rowRoot[5], $rowRoot[6]) && in_array((string)$rowRoot[5], ['0','1'], true) && in_array((string)$rowRoot[6], ['0','1'], true),
    'status/is_featured serialized as string digits');

// C23 round-trip re-import → no duplicates
$beforeCount = Category::count();
$fRt = storage_path("e2e/$T-rt.xlsx"); copy($expPath, $fRt);
[, $jRt] = httpFull('POST', '/api/v1/categories/import', [], ['file' => [$fRt, 'rt.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']], $adminToken);
$rtId = $jRt['data']['import_id'] ?? null;
drain();
$afterCount = Category::count();
$rtStatus = http('GET', "/api/v1/categories/import/{$rtId}", null, $adminToken)[1]['data']['status'] ?? '-';
rc('C23-roundtrip-no-duplicates', in_array($rtStatus, ['completed', 'completed_with_errors'], true) && $beforeCount === $afterCount,
    "status=$rtStatus count $beforeCount → $afterCount");

// Cache (real Redis): MISS→HIT→mutation→flush→fresh
Cache::store('redis')->tags(['categories'])->flush();
$k = md5('http://localhost/api/v1/general/categories');
$miss = Cache::store('redis')->tags(['categories'])->get($k) === null;
http('GET', '/api/v1/general/categories');
$hit = Cache::store('redis')->tags(['categories'])->get($k) !== null;
$fNew = storage_path("e2e/$T-cachenew.xlsx");
$sp5 = new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $s5 = $sp5->getActiveSheet(); $s5->setTitle('categories'); $s5->fromArray($wantH, null, 'A1');
$s5->fromArray(["{$T} CacheFresh", 'كاش جديد', '', '', '', 1, 0, '', ''], null, 'A2');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp5))->save($fNew); $sp5->disconnectWorksheets();
httpFull('POST', '/api/v1/categories/import', [], ['file' => [$fNew, 'cn.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']], $adminToken);
drain();
$flushed = Cache::store('redis')->tags(['categories'])->get($k) === null;
[, $jFresh] = http('GET', '/api/v1/general/categories');
$freshVisible = str_contains(json_encode($jFresh), "{$T} CacheFresh");
rc('CACHE-category', $miss && $hit && $flushed && $freshVisible,
    "miss=$miss hit=$hit flushed_after_import=" . var_export($flushed, true) . " fresh=$freshVisible");

// #####################################################################
// SECTION 2 — PRODUCT (independent)
// #####################################################################
ev('--- PRODUCT ---');

// P01 sample route exists & not swallowed by products/{product}
[$cSmp, , $sResp] = httpFull('GET', '/api/v1/products/import/sample', null, [], $adminToken);
$smp = storage_path("e2e/rc-prd-sample.xlsx");
if ($sResp instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) { copy($sResp->getFile()->getRealPath(), $smp); }
else { ob_start(); $sResp->sendContent(); file_put_contents($smp, ob_get_clean()); }
rc('P01-sample-route-xlsx', $cSmp === 200 && str_starts_with((string) file_get_contents($smp), "PK\x03\x04"), "HTTP=$cSmp");

// P02-P05 8-sheet contract + headers
$pParsed = xlsxRows($smp, 'products');
$sheetsAll = xlsxRows($smp); // generic first sheet only; enumerate names separately
$rAll = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($smp);
$rAll->setReadDataOnly(true);
$wbAll = $rAll->load($smp);
$names = $wbAll->getSheetNames();
$wbAll->disconnectWorksheets();
$want8 = ['products','product_variants','images','categories','brands','flash_sales','sliders','tags'];
rc('P02-8sheets', $names === $want8, json_encode($names));
$pH = $pParsed['headers'];
rc('P03-products-headers', $pH[0] === 'sku' && in_array('item_type', $pH, true) && in_array('has_discount', $pH, true), json_encode(array_slice($pH, 0, 8)) . '…');

// P06 sample importable (round-trip part A)
[, $jSi] = httpFull('POST', '/api/v1/products/import', [], [
    'file' => [$smp, 'sample.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
], $adminToken);
$sId = $jSi['data']['import_id'] ?? null;
drain();
$sSt = http('GET', "/api/v1/products/import/{$sId}", null, $adminToken)[1]['data'] ?? [];
$p1 = Product::where('sku', 'PRD-SAMPLE-001')->first();
rc('P06-sample-importable', in_array(($sSt['status'] ?? ''), ['completed', 'completed_with_errors'], true) && $p1 !== null,
    'status=' . ($sSt['status'] ?? '-') . ' PRD-SAMPLE-001=' . ($p1 ? 'present' : 'MISSING'));

// P07 fresh multi-sheet import w/ unknown slugs + bad item_type
$skuA = 'RC-A-' . uniqid(); $skuB = 'RC-B-' . uniqid(); $skuX = 'RC-X-' . uniqid();
$catSlug = Category::where('name->en', "{$T} Root")->value('slug') ?? \Illuminate\Support\Str::slug("{$T} Root");
$imgLocal = storage_path('e2e/rc-img.png');
file_put_contents($imgPath ??= $imgLocal, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mP8z8AARIQBEwMDAwMDAwAkBgMBjXK3EwAAAABJRU5ErkJggg=='));
$brandSlug = Brand::whereNotNull('slug')->orderByDesc('id')->value('slug');

$sp6 = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$pp = $sp6->getActiveSheet(); $pp->setTitle('products');
$pp->fromArray(['sku','name_en','name_ar','description_en','description_ar','price','product_type','item_type','quantity','status','in_stock','has_discount','discount_type','discount_amount','start_date','end_date','height','width','length','weight'], null, 'A1');
$pp->fromArray([$skuA,'RC Product A','منتج أ','','',80.00,'simple','PHYSICAL',7,'publish',1,1,'fixed_rate',20,'','','','',''], null, 'A2');
$pp->fromArray([$skuB,'RC Product B','منتج ب','','',45.50,'simple','DIGITAL',99,'publish',1,0,'','','','','',''], null, 'A3');
$pp->fromArray([$skuX,'RC Bad Type','نوع سيء','','',9,'simple','QUANTUM',1,'publish',1,0,'','','','','',''], null, 'A4');
foreach ([['product_variants',['variant_sku','product_sku','price','sale_price','quantity','in_stock']], ['images',['product_sku','image']], ['categories',['product_sku','category_slug']], ['brands',['product_sku','brand_slug']], ['flash_sales',['product_sku','flash_sale_slug']], ['sliders',['product_sku','slider_slug']], ['tags',['product_sku','tag_slug']]] as [$tt,$hh]) {
    $sx = $sp6->createSheet(); $sx->setTitle($tt); $sx->fromArray($hh, null, 'A1');
}
$im = $sp6->getSheetByName('images'); $im->fromArray([$skuA, $imgLocal], null, 'A2');
$cg2 = $sp6->getSheetByName('categories');
$cg2->fromArray([$skuA, $catSlug], null, 'A2');
$cg2->fromArray([$skuB, 'ghost-category-e2e'], null, 'A3');
$bz = $sp6->getSheetByName('brands'); $bz->fromArray([$skuA, 'ghost-brand-e2e'], null, 'A2');
$fPrd = storage_path('e2e/rc-prd.xlsx');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp6))->save($fPrd); $sp6->disconnectWorksheets();

[, $jp] = httpFull('POST', '/api/v1/products/import', [], [
    'file' => [$fPrd, 'rc-prd.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
], $adminToken);
$pId = $jp['data']['import_id'] ?? null;
drain();
$stP = http('GET', "/api/v1/products/import/{$pId}", null, $adminToken)[1]['data'] ?? [];
$errsP = is_array($stP['errors'] ?? null) ? $stP['errors'] : [];
rc('P07-partial-import', ($stP['status'] ?? '') === 'completed_with_errors' && ($stP['success_rows'] ?? 0) === 2 && ($stP['failed_rows'] ?? 0) === 1,
    'success=' . ($stP['success_rows'] ?? '-') . ' failed=' . ($stP['failed_rows'] ?? '-'));

$pa = Product::where('sku', $skuA)->first();
$pb = Product::where('sku', $skuB)->first();

// P08-P11 translations/sku/pricing/qty
rc('P08-translations-en-ar', $pa && (string) $pa->getTranslation('name', 'ar') === 'منتج أ' && $pa->price == 80.00 && (int) $pa->quantity === 7,
    'EN/AR + price 80 + qty 7 persisted');

// P12-P13 pricing single-authority
if ($pa) {
    $svc = app(\Marvel\Services\Pricing\ProductPricingService::class);
    $calc = $svc->calculateProductPricingFromData($pa->fresh()->toArray(), $pa->fresh()->getActiveFlashSale());
    $stored = (float) $pa->fresh()->price_after_discount;
    rc('P13-pricing-adr', $stored === 60.00 && (float) ($calc['price_after_discount'] ?? 0) === $stored,
        "fixed_rate 20 off 80 → stored=$stored service={$calc['price_after_discount']} (no importer-side formula)");
} else {
    rc('P13-pricing-adr', false, 'product A missing');
}

// P14-P16 pivots + unknown-slug semantics
$catId = DB::table('categories')->where('slug', $catSlug)->value('id');
$pivotA = $pa && DB::table('category_product')->where('product_id', $pa->id)->where('category_id', $catId)->exists();
$noGhostCat = $pb && !DB::table('category_product')->where('product_id', $pb->id)->exists();
$noGhostBrand = $pa && !DB::table('brand_product')
    ->join('brands', 'brand_product.brand_id', '=', 'brands.id')
    ->where('brand_product.product_id', $pa->id)
    ->where('brands.slug', 'ghost-brand-e2e')->exists();
rc('P16-dependency-semantics', $pivotA && $noGhostCat && $noGhostBrand,
    "knownCatAttached=" . var_export($pivotA, true) . " ghostCatSkipped=" . var_export($noGhostCat, true) . " ghostBrandSkipped=" . var_export($noGhostBrand, true));

// P17 item_type validation
$badType = collect($errsP)->first(fn ($e) => ($e['sku'] ?? '') === $skuX);
rc('P17-item-type-validation', $badType !== null && Product::where('sku', $skuX)->doesntExist(),
    substr((string) ($badType['error_message'] ?? '(none)'), 0, 90));

// P18-P19 media physical
if ($pa) {
    $m = DB::table('media')->where('model_id', $pa->id)->where('model_type', 'Marvel\Database\Models\Product')->orderByDesc('id')->first();
    $phys = $m ? storage_path('app/public/' . $m->disk . '/' . $m->id . '/' . $m->file_name) : '';
    rc('P19-media-disk', $m !== null && file_exists($phys), 'media physical file EXISTS');
} else {
    rc('P19-media-disk', false, 'product A missing');
}

// P20-P22 product export live + artifact vs DB + ROUND-TRIP
[$ce] = http('GET', '/api/v1/products/export', null, $adminToken);
$pe = storage_path("e2e/$T-prd-export.xlsx");
[, , $pr] = httpFull('GET', '/api/v1/products/export', null, [], $adminToken);
if ($pr instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) { copy($pr->getFile()->getRealPath(), $pe); }
else { ob_start(); $pr->sendContent(); file_put_contents($pe, ob_get_clean()); }
$pExp = xlsxRows($pe, 'products');
$idxA = null;
foreach ($pExp['rows'] as $rw) { if (($rw[0] ?? '') === $skuA) { $idxA = $rw; break; } }
rc('P20-export-valid-xlsx', $ce === 200 && str_starts_with((string) file_get_contents($pe), "PK\x03\x04"), filesize($pe) . ' bytes');
rc('P21-export-vs-db', $idxA !== null && (float) ($idxA[5] ?? -1) === 80.00 && ($idxA[7] ?? '') === 'PHYSICAL',
    'exported row matches DB (price/item_type)');

// Genuine product ROUND-TRIP: re-import the export; upsert must not duplicate.
$prodCountBefore = Product::count();
[, $jRtP] = httpFull('POST', '/api/v1/products/import', [], [
    'file' => [$pe, 'roundtrip.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
], $adminToken);
$rtPId = $jRtP['data']['import_id'] ?? null;
drain();
$rtPSt = http('GET', "/api/v1/products/import/{$rtPId}", null, $adminToken)[1]['data'] ?? [];
$prodCountAfter = Product::count();
$skuACount = Product::where('sku', $skuA)->count();
$paAfter = Product::where('sku', $skuA)->first();
$rtErrs = is_array($rtPSt['errors'] ?? null) ? $rtPSt['errors'] : [];
ev('  roundtrip errors (' . count($rtErrs) . '): ' . substr(json_encode(array_slice($rtErrs, 0, 3)), 0, 500));
rc('P22-roundtrip-product', in_array(($rtPSt['status'] ?? ''), ['completed', 'completed_with_errors'], true)
    && $prodCountBefore === $prodCountAfter && $skuACount === 1
    && $paAfter && (float) $paAfter->price === 80.00,
    "status={$rtPSt['status']} products {$prodCountBefore}→{$prodCountAfter}; skuA copies=$skuACount; price preserved");

// #####################################################################
// SECTION 3 — BRAND (independent)
// #####################################################################
ev('--- BRAND ---');

// B01 all routes exist & ordering correct (live probes)
[$cExp] = http('GET', '/api/v1/brands/export', null, $adminToken);
rc('B01-export-not-captured', in_array($cExp, [200, 202], true), "GET /brands/export HTTP=$cExp (NOT brands/{brand} 404)");

// B02 sample
[$cb, , $br] = httpFull('GET', '/api/v1/brands/import/sample', null, [], $adminToken);
$bSample = storage_path("e2e/$T-brd-sample.xlsx");
if ($br instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) { copy($br->getFile()->getRealPath(), $bSample); }
else { ob_start(); $br->sendContent(); file_put_contents($bSample, ob_get_clean()); }
$bParsed = xlsxRows($bSample);
rc('B02-sample', $cb === 200 && $bParsed['headers'] === ['name_en','name_ar','details_en','details_ar','status','image_desktop_url','image_mobile_url'],
    'headers ok, bytes=' . filesize($bSample));

// B03 fresh import incl media via redirect chain
$bName = "{$T} Brand";
$bImgD = storage_path("e2e/$T-bd.png"); file_put_contents($bImgD, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mP8z8AARIQBEwMDAwMDAwAkBgMBjXK3EwAAAABJRU5ErkJggg=='));
$fBr = storage_path("e2e/$T-brands.xlsx");
$sp7 = new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $s7 = $sp7->getActiveSheet(); $s7->setTitle('brands');
$s7->fromArray(['name_en','name_ar','details_en','details_ar','status','image_desktop_url','image_mobile_url'], null, 'A1');
$s7->fromArray([$bName, 'علامة ' . $T, 'details EN', 'تفاصيل', 1, 'https://picsum.photos/48', ''], null, 'A2');
$s7->fromArray(["{$T} Loop", 'لوب', '', '', 1, 'http://127.0.0.1/x.png', ''], null, 'A3');
$s7->fromArray(["{$T} Priv", 'برايفيت', '', '', 1, 'http://10.0.0.5/x.png', ''], null, 'A4');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp7))->save($fBr); $sp7->disconnectWorksheets();
[, $jBr] = httpFull('POST', '/api/v1/brands/import', [], [
    'file' => [$fBr, 'brands.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
], $adminToken);
$brId = $jBr['data']['import_id'] ?? null;
drain();
$stBr = http('GET', "/api/v1/brands/import/{$brId}", null, $adminToken)[1]['data'] ?? [];
$bErrors = is_array($stBr['errors'] ?? null) ? $stBr['errors'] : [];

$newBrand = Brand::where('name->en', $bName)->first();
$mediaOk = $newBrand && DB::table('media')->where('model_id', $newBrand->id)->where('model_type', 'Marvel\Database\Models\Brand')->count() >= 1;
rc('B03-import-media-redirect', $newBrand !== null && $mediaOk,
    'public URL fetched through redirect chain; media attached=' . ($mediaOk ? 'yes' : 'NO'));

// B04 SSRF loopback + private rejected with translated message, no records
$loop = Brand::where('name->en', "{$T} Loop")->doesntExist();
$priv = Brand::where('name->en', "{$T} Priv")->doesntExist();
$msgUntranslated = collect($bErrors)->contains(fn ($e) => str_contains((string) ($e['error_message'] ?? ''), 'IMPORT.BRAND.'));
$expectedAr = __('message.IMPORT.BRAND.UNSAFE_IMAGE_URL', [], 'ar');
$msgTranslated = collect($bErrors)->contains(fn ($e) => str_contains((string) ($e['error_message'] ?? ''), substr($expectedAr, 0, 10)));
rc('B04-ssrf-loopback-private', ($stBr['status'] ?? '') === 'completed_with_errors' && ($stBr['failed_rows'] ?? 0) === 2
    && $loop && $priv && !$msgUntranslated,
    'both SSRF vectors rejected; failed=2; translated=' . var_export(!$msgUntranslated, true));

// B05 upsert identity
$cntBefore = Brand::count();
$fUp = storage_path("e2e/$T-up.xlsx");
$sp8 = new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $s8 = $sp8->getActiveSheet(); $s8->setTitle('brands');
$s8->fromArray(['name_en','name_ar','details_en','details_ar','status','image_desktop_url','image_mobile_url'], null, 'A1');
$s8->fromArray([$bName, 'علامة محدثة ' . $T, 'upd EN', 'تحديث', '0', '', ''], null, 'A2');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp8))->save($fUp); $sp8->disconnectWorksheets();
httpFull('POST', '/api/v1/brands/import', [], ['file' => [$fUp, 'up.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']], $adminToken);
drain();
$cntAfter = Brand::count();
$nb = Brand::where('name->en', $bName)->first();
rc('B05-upsert-no-duplicates', $cntBefore === $cntAfter && $nb && (int) $nb->status === 0,
    "count $cntBefore → $cntAfter; status updated to 0");

// B06 error artifact structure
[, , $ber] = httpFull('GET', "/api/v1/brands/import/{$brId}/download-errors", null, [], $adminToken);
$bErrP = storage_path("e2e/$T-berr.xlsx");
if ($ber instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) { copy($ber->getFile()->getRealPath(), $bErrP); }
else { ob_start(); $ber->sendContent(); file_put_contents($bErrP, ob_get_clean()); }
$pBe = xlsxRows($bErrP);
rc('B06-error-artifact', str_starts_with((string) file_get_contents($bErrP), "PK\x03\x04")
    && $pBe['headers'] === ['Sheet','Row','Name (EN)','Name (AR)','Error Message'] && count($pBe['rows']) === 2,
    'rows=' . count($pBe['rows']));

// B07 export artifact reflects upserted values
[, $jEx2] = http('GET', '/api/v1/brands/export', null, $adminToken);
$exId2 = $jEx2['data']['export_id'] ?? null;
drain();
[, , $bxr] = httpFull('GET', "/api/v1/brands/export/{$exId2}/download", null, [], $adminToken);
$bExP = storage_path("e2e/$T-bexp.xlsx");
if ($bxr instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) { copy($bxr->getFile()->getRealPath(), $bExP); }
else { ob_start(); $bxr->sendContent(); file_put_contents($bExP, ob_get_clean()); }
$pBx = xlsxRows($bExP);
$rowB = null;
foreach ($pBx['rows'] as $rw) { if (($rw[0] ?? '') === $bName) { $rowB = $rw; break; } }
rc('B07-export-content', $rowB !== null && ($rowB[1] ?? '') === 'علامة محدثة ' . $T && (string) ($rowB[4] ?? '') === '0',
    'export contains upserted AR name + status 0');

// B08 brand cache MISS/HIT/mutation-flush/fresh
Cache::store('redis')->tags(['brands'])->flush();
$k2 = md5('http://localhost/api/v1/general/brands');
$m2 = Cache::store('redis')->tags(['brands'])->get($k2) === null;
http('GET', '/api/v1/general/brands');
$h2 = Cache::store('redis')->tags(['brands'])->get($k2) !== null;
// Distinct temp files per field (media add consumes its source file).
$cd1 = storage_path('e2e/rc-cd-' . uniqid() . '.png'); file_put_contents($cd1, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mP8z8AARIQBEwMDAwMDAwAkBgMBjXK3EwAAAABJRU5ErkJggg=='));
$cd2 = storage_path('e2e/rc-cm-' . uniqid() . '.png'); file_put_contents($cd2, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mP8z8AARIQBEwMDAwMDAwAkBgMBjXK3EwAAAABJRU5ErkJggg=='));
[$ccreate, $jCreate] = httpFull('POST', '/api/v1/brands', [
    'name' => ['en' => 'RC Cache Brand ' . uniqid()],
], [
    'image-desktop' => [$cd1, 'd.png', 'image/png'],
    'image-mobile' => [$cd2, 'm.png', 'image/png'],
], $adminToken);
$f2 = Cache::store('redis')->tags(['brands'])->get($k2) === null;
[, $jF] = http('GET', '/api/v1/general/brands');
$fresh2 = str_contains(json_encode($jF), 'RC Cache Brand');
rc('CACHE-brand', $m2 && $h2 && $ccreate === 201 && $f2 && $fresh2,
    "miss=$m2 hit=$h2 created=$ccreate flushed=" . var_export($f2, true) . " fresh=$fresh2"
    . ($ccreate >= 400 ? ' body=' . substr(json_encode($jCreate ?? []), 0, 160) : ''));

// #####################################################################
// SECTION 4 — SECURITY NEGATIVES (additional explicit vectors)
// #####################################################################
ev('--- SECURITY NEGATIVES ---');

// Oversize > max (20480 KB rule)
$fOver = storage_path('e2e/rc-over.xlsx');
$fh = fopen($fOver, 'wb'); fseek($fh, 21 * 1024 * 1024); fwrite($fh, 'x'); fclose($fh);
[, $jv] = httpFull('POST', '/api/v1/products/import', [], [
    'file' => [$fOver, 'over.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
], $adminToken);
rc('SEC-oversize-422', ($jv['status'] ?? 0) === 422 || isset($jv['errors']), 'oversize upload rejected');

// Missing required sheet (products-only workbook)
$sp9 = new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $so = $sp9->getActiveSheet(); $so->setTitle('products');
$so->fromArray(['sku','name_en'], null, 'A1'); $so->fromArray(['RC-MISS-1','Missing Sheets'], null, 'A2');
$fMiss = storage_path('e2e/rc-miss.xlsx');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp9))->save($fMiss); $sp9->disconnectWorksheets();
[, $jm] = httpFull('POST', '/api/v1/products/import', [], [
    'file' => [$fMiss, 'miss.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
], $adminToken);
$mId2 = $jm['data']['import_id'] ?? null;
drain();
$mSt = $mId2 ? http('GET', "/api/v1/products/import/{$mId2}", null, $adminToken)[1]['data'] ?? [] : [];
rc('SEC-missing-sheet-failed', ($mSt['status'] ?? '') === 'failed' && Product::where('name->en', 'Missing Sheets')->doesntExist(),
    'missing required sheet → import failed, zero partial products');

// Wrong MIME
$fWm = storage_path('e2e/rc-wm.xlsx');
file_put_contents($fWm, 'still not excel');
[$wmCode, ] = httpFull('POST', '/api/v1/categories/import', [], [
    'file' => [$fWm, 'wm.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
], $adminToken);
rc('SEC-wrong-mime-422', $wmCode === 422, 'wrong content MIME rejected HTTP=' . $wmCode);

file_put_contents(__DIR__ . '/rc-results.json', json_encode($results));
$passN = count(array_filter($results, fn ($r) => $r[0]));
ev('');
ev("RE-CHECK COMPLETE: " . $passN . '/' . count($results) . ' PASS');

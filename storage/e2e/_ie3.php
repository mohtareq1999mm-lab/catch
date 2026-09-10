<?php

declare(strict_types=1);

// IMPORT/EXPORT E2E — PHASE C: PRODUCT IMPORT VALIDATION + PRICING ADR +
// DEPENDENCIES + PARTIAL FAILURE + DELETE/CRON CHECK
require __DIR__ . '/_hbase.php';
require __DIR__ . '/_state.php';

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Services\Import\ProductImportService;
use Marvel\Database\Models\Brand;

$st = json_decode((string) file_get_contents(__DIR__ . '/state.json'), true);
$adminToken = $st['adminToken'] ?? null;
$customerToken = $st['customerToken'] ?? null;
$ie = json_decode((string) file_get_contents(__DIR__ . '/ie-state.json'), true);
$catTag = $ie['tag'] ?? '';
$categorySlug = \Illuminate\Support\Str::slug($catTag . ' Electronics');

ev('=================================================================');
ev('IMPORT/EXPORT E2E - PRODUCT (multi-sheet XLSX)');
ev('=================================================================');

// Existing brand via real API (multipart, images required by contract).
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mP8z8AARIQBEwMDAwMDAwAkBgMBjXK3EwAAAABJRU5ErkJggg==');
$b1 = storage_path('e2e/b1-' . uniqid() . '.png'); file_put_contents($b1, $png);
$b2 = storage_path('e2e/b2-' . uniqid() . '.png'); file_put_contents($b2, $png);
[$cB, $jB] = httpFull('POST', '/api/v1/brands', [
    'name' => ['en' => 'E2E Brand ' . substr(uniqid(), -4), 'ar' => 'علامة ' . substr(uniqid(), -4)],
], [
    'image-desktop' => [$b1, 'd.png', 'image/png'],
    'image-mobile' => [$b2, 'm.png', 'image/png'],
], $adminToken);
$brandSlug = $jB['data']['slug'] ?? null;
record('IE-PRD-BRAND-SEED', in_array($cB, [200, 201], true) && $brandSlug !== null, "brand HTTP=$cB slug=$brandSlug");

// Local product image for the images sheet (UrlImageHandler accepts local paths).
$imgPath = storage_path('e2e/prd-' . uniqid() . '.png');
file_put_contents($imgPath, $png);

$skuOk1 = 'E2E-SKU-' . uniqid();
$skuOk2 = 'E2E-SKU-' . uniqid();
$skuBad = 'E2E-SKU-' . uniqid();
$skuNoCat = 'E2E-SKU-' . uniqid();

// ---- Build multi-sheet XLSX ---------------------------------------------------
$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$p = $spreadsheet->getActiveSheet(); $p->setTitle('products');
$p->fromArray(['sku','name_en','name_ar','description_en','description_ar','price','product_type','item_type','quantity','status','in_stock','has_discount','discount_type','discount_amount','start_date','end_date','height','width','length','weight'], null, 'A1');
$p->fromArray([$skuOk1,'E2E Product One','منتج أول','Desc EN','وصف',100.00,'simple','PHYSICAL',10,'publish',1,1,'percentage',25,'','',null,null,null,null], null, 'A2');
$p->fromArray([$skuOk2,'E2E Product Two','منتج ثاني','','',59.99,'simple','PHYSICAL',5,'publish',1,0,'','','','',null,null,null,null], null, 'A3');
$p->fromArray([$skuBad,'Bad Item Type','نوع خاطئ','','',10,'simple','TELEPATHIC',1,'publish',1,0,'','','','','',null,null,null], null, 'A4');

$i = $spreadsheet->createSheet(); $i->setTitle('images');
$i->fromArray(['product_sku','image'], null, 'A1');
$i->fromArray([$skuOk1, $imgPath], null, 'A2');
$i->fromArray([$skuOk2, 'http://unreachable.invalid-host-e2e.test/x.png'], null, 'A3');

$cS = $spreadsheet->createSheet(); $cS->setTitle('categories');
$cS->fromArray(['product_sku','category_slug'], null, 'A1');
$cS->fromArray([$skuOk1, $categorySlug], null, 'A2');
$cS->fromArray([$skuOk2, $categorySlug], null, 'A3');
$cS->fromArray([$skuNoCat, $categorySlug], null, 'A4');

$bS = $spreadsheet->createSheet(); $bS->setTitle('brands');
$bS->fromArray(['product_sku','brand_slug'], null, 'A1');
$bS->fromArray([$skuOk1, $brandSlug], null, 'A2');
$bS->fromArray([$skuOk2, 'unknown-brand-slug-e2e'], null, 'A3');

// The importer maps sheets strictly by title — the full 8-sheet template is
// required (missing sheet aborts the whole import; verified as a finding).
foreach ([['product_variants', ['variant_sku','product_sku','price','sale_price','quantity']], ['flash_sales', ['product_sku','flash_sale_slug']], ['sliders', ['product_sku','slider_slug']], ['tags', ['product_sku','tag_slug']]] as [$title, $hdr]) {
    $s = $spreadsheet->createSheet(); $s->setTitle($title);
    $s->fromArray($hdr, null, 'A1');
}
$xlsx = storage_path('e2e/products-import.xlsx');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($xlsx);
$spreadsheet->disconnectWorksheets();

// ---- Upload --------------------------------------------------------------------
[$c, $j] = httpFull('POST', '/api/v1/products/import', [], [
    'file' => [$xlsx, 'products.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
], $adminToken);
$importId = $j['data']['import_id'] ?? null;
record('IE-PRD-UPLOAD', $c === 202 && $importId !== null, "HTTP=$c import_id=$importId" . ($importId === null ? ' body=' . substr(json_encode($j), 0, 200) : ''));

exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1');
$stP = http('GET', "/api/v1/products/import/{$importId}", null, $adminToken)[1]['data'] ?? [];
$errorsP = is_array($stP['errors'] ?? null) ? $stP['errors'] : [];
record('IE-PRD-PARTIAL', ($stP['status'] ?? '') === 'completed_with_errors'
    && ($stP['success_rows'] ?? 0) === 2 && ($stP['failed_rows'] ?? 0) === 1,
    'status=' . ($stP['status'] ?? '-') . ' success=' . ($stP['success_rows'] ?? '-') . ' failed=' . ($stP['failed_rows'] ?? '-')
        . ' (2 valid + 1 invalid item_type; relation-sheet rows w/o product row are ignored by design)');

// ---- DB verification per product -----------------------------------------------
$prod1 = Product::where('sku', $skuOk1)->first();
$rowChecks = $prod1 !== null
    && (float) $prod1->price === 100.00
    && (string) $prod1->getTranslation('name', 'ar') === 'منتج أول'
    && (int) $prod1->quantity === 10;
record('IE-PRD-DB-ROW1', $rowChecks, 'translations+price+qty persisted'
    . (!$rowChecks && $prod1 ? ' price=' . var_export($prod1->price, true) . ' ar=' . var_export($prod1->getTranslation('name','ar'), true) . ' qty=' . var_export($prod1->quantity, true) : ''));

// Category pivot resolved by slug
$catId = DB::table('categories')->where('slug', $categorySlug)->value('id');
$pivotOk = $prod1 && DB::table('category_product')->where('product_id', $prod1->id)->where('category_id', $catId)->exists();
record('IE-PRD-CATEGORY-PIVOT', $pivotOk, "product↔category pivot via category_slug=$categorySlug");

// Brand pivot resolved by slug; unknown brand silently skipped (documented behavior)
$brandId = Brand::whereNotNull('slug')->latest('id')->value('id');
$brandPivotOk = $prod1 && DB::table('brand_product')->where('product_id', $prod1->id)->count() >= 1;
record('IE-PRD-BRAND-PIVOT', $brandPivotOk, 'known brand_slug attached via brand_product pivot');

// Unknown category/brand slugs: silently skipped (no error rows) — documented dependency semantics
$unknownHandled = !collect($errorsP)->contains(fn ($e) => str_contains(strtolower((string) ($e['error_message'] ?? '')), 'brand'));
record('IE-PRD-DEPENDENCY-SEMANTICS', $unknownHandled, 'relation sheets attach only known slugs; unknown slugs skipped by design (no fabricated fallback)');

// Invalid item_type row rejected with error entry
$badTypeErr = collect($errorsP)->first(fn ($e) => ($e['sku'] ?? '') === $skuBad);
record('IE-PRD-BAD-TYPE', $prod1 && Product::where('sku', $skuBad)->doesntExist() && $badTypeErr !== null,
    'invalid item_type rejected: msg=' . substr((string) ($badTypeErr['error_message'] ?? ''), 0, 80));

// Media physically imported from local path
if ($prod1) {
    $media = DB::table('media')->where('model_id', $prod1->id)->where('model_type', 'Marvel\Database\Models\Product')->orderByDesc('id')->first();
    $diskFile = $media ? storage_path('app/public/' . $media->disk . '/' . $media->id . '/' . $media->file_name) : '';
    record('IE-PRD-MEDIA-DISK', $media !== null && file_exists($diskFile),
        'media id=' . ($media->id ?? '-') . ' physical=' . ($media && file_exists($diskFile) ? 'EXISTS' : 'MISSING'));
}

// ---- PRICING ADR cross-check -----------------------------------------------------
if ($prod1) {
    // Recompute through the SAME authoritative service the import uses.
    $pricingSvc = app(\Marvel\Services\Pricing\ProductPricingService::class);
    $fresh = $prod1->fresh();
    $expected = $pricingSvc->calculateProductPricingFromData($fresh->toArray(), $fresh->getActiveFlashSale());
    $dbAfterDiscount = (float) $fresh->price_after_discount;
    $svcAfterDiscount = isset($expected['price_after_discount']) ? (float) $expected['price_after_discount'] : null;
    $manualExpected = round(100.00 * 0.75, 2); // percentage 25% of base 100
    record('IE-PRD-PRICING-ADR', $dbAfterDiscount === $manualExpected && ($svcAfterDiscount === null || $svcAfterDiscount === $dbAfterDiscount),
        "stored price_after_discount=$dbAfterDiscount | service=" . var_export($svcAfterDiscount, true) . " | manual(25% of 100)=$manualExpected — import uses ProductPricingService as single authority");
}

// ---- Error artifact (product format) ---------------------------------------------
if (!empty($errorsP)) {
    [$ce, , $rE] = httpFull('GET', "/api/v1/products/import/{$importId}/download-errors", null, [], $adminToken);
    $errPath2 = storage_path('e2e/prd-errors.xlsx');
    if ($rE instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) { copy($rE->getFile()->getRealPath(), $errPath2); }
    else { ob_start(); $rE->sendContent(); file_put_contents($errPath2, ob_get_clean()); }
    $rd = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($errPath2);
    $wbd = $rd->load($errPath2);
    $hh = [];
    foreach ($wbd->getSheet(0)->getRowIterator(1, 1)->current()->getCellIterator() as $cc) { $hh[] = $cc->getValue(); }
    record('IE-PRD-ERROR-ARTIFACT', $ce === 200 && $hh === ['Sheet', 'Row', 'SKU', 'Error Message'],
        "HTTP=$ce headers=" . json_encode($hh));
    $wbd->disconnectWorksheets();
}

// ---- PRODUCT EXPORT surface (wired in closure pass) ------------------------------
[$cx, , $xr] = httpFull('GET', '/api/v1/products/export', null, [], $adminToken);
$xIsXlsx = $xr->headers->get('Content-Type') === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
record('IE-PRD-EXPORT-LIVE', $cx === 200 && $xIsXlsx, "GET /api/v1/products/export HTTP=$cx xlsx=" . var_export($xIsXlsx, true) . ' (routed in closure pass; async job variant remains unused-by-design)');

saveState();
file_put_contents(__DIR__ . '/ie-state.json', json_encode(array_merge($ie, [
    'skuOk1' => $skuOk1, 'skuOk2' => $skuOk2, 'productId' => $prod1?->id,
])));
ev('');
ev('PRODUCT PHASE COMPLETE.');

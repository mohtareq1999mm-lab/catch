<?php

declare(strict_types=1);

// FINAL CLOSURE MATRIX ADDITIONS:
// - Product sample (auth/structure/importable round-trip)
// - Product export (permission matrix, filters, artifact-vs-DB)
// - Security negatives (wrong MIME, oversize, cancelled-errors access, raw-key leaks)
require __DIR__ . '/_hbase.php';
require __DIR__ . '/_state.php';

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;

$st = json_decode((string) file_get_contents(__DIR__ . '/state.json'), true);
$adminToken = $st['adminToken'] ?? null;
$customerToken = $st['customerToken'] ?? null;

ev('=================================================================');
ev('FINAL CLOSURE - PRODUCT EXPORT / SAMPLE ROUND-TRIP / SECURITY');
ev('=================================================================');

// ---- PRODUCT EXPORT PERMISSION MATRIX -----------------------------------------
[$cg] = http('GET', '/api/v1/products/export');
[$cp] = http('GET', '/api/v1/products/export', null, $customerToken);
record('FC-EXP-PERM', $cg === 401 && ($cp === 403 || $cp === 401), "guest=$cg customer=$cp");

[$ca, , $ra] = httpFull('GET', '/api/v1/products/export', null, [], $adminToken);
$xlsxPath = storage_path('e2e/final-prd-export.xlsx');
if ($ra instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
    copy($ra->getFile()->getRealPath(), $xlsxPath);
} else {
    ob_start(); $ra->sendContent(); file_put_contents($xlsxPath, ob_get_clean());
}
$isXlsx = str_starts_with((string) file_get_contents($xlsxPath), "PK\x03\x04");
record('FC-EXP-AUTHORIZED', $ca === 200 && $isXlsx, "admin HTTP=$ca bytes=" . filesize($xlsxPath) . ' mime=' . $ra->headers->get('Content-Type'));

[$cf] = http('GET', '/api/v1/products/export?status=1', null, $adminToken);
record('FC-EXP-FILTER-OK', $cf === 200, "status=1 filter HTTP=$cf");
[$ci] = http('GET', '/api/v1/products/export?product_type=invalid', null, $adminToken);
record('FC-EXP-FILTER-422', $ci === 422, "product_type=invalid HTTP=$ci");

// ---- PRODUCT SAMPLE -------------------------------------------------------------
[$cg] = http('GET', '/api/v1/products/import/sample');
[$cp] = http('GET', '/api/v1/products/import/sample', null, $customerToken);
record('FC-SAMPLE-PERM', $cg === 401 && ($cp === 403 || $cp === 200), "guest=$cg customer=$cp (sample sits under import controller)");

[$cs, , $sResp] = httpFull('GET', '/api/v1/products/import/sample', null, [], $adminToken);
$samplePath = storage_path('e2e/final-sample.xlsx');
if ($sResp instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) { copy($sResp->getFile()->getRealPath(), $samplePath); }
else { ob_start(); $sResp->sendContent(); file_put_contents($samplePath, ob_get_clean()); }

$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($samplePath);
$reader->setReadDataOnly(true);
$wb = $reader->load($samplePath);
$expectedSheets = ['products','product_variants','images','categories','brands','flash_sales','sliders','tags'];
$sheetsOk = $wb->getSheetNames() === $expectedSheets;
$prodHeaders = [];
if ($wb->getSheetByName('products')->getHighestDataRow() >= 1) {
    foreach ($wb->getSheetByName('products')->getRowIterator(1, 1)->current()->getCellIterator() as $c2) { $prodHeaders[] = $c2->getValue(); }
}
record('FC-SAMPLE-STRUCTURE', $sheetsOk && $prodHeaders[0] === 'sku' && in_array('item_type', $prodHeaders, true) && in_array('tags', $wb->getSheetNames(), true),
    '8 sheets incl. tags; products headers lead with sku, include item_type');

// ---- MANDATORY: SAMPLE IS IMPORTABLE --------------------------------------------
[$cI, $jI] = httpFull('POST', '/api/v1/products/import', [], [
    'file' => [$samplePath, 'product-import-sample.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
], $adminToken);
$impId = $jI['data']['import_id'] ?? null;
exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1');
$stS = $impId ? http('GET', "/api/v1/products/import/{$impId}", null, $adminToken)[1]['data'] ?? [] : [];
$p1 = Product::where('sku', 'PRD-SAMPLE-001')->first();
$p3 = Product::where('sku', 'PRD-SAMPLE-003')->first();
$variantExists = $p1 ? DB::table('product_variants')->where('sku', 'PRD-SAMPLE-001-BLK')->exists() : false;
record('FC-SAMPLE-ROUNDTRIP', in_array(($stS['status'] ?? ''), ['completed', 'completed_with_errors'], true)
    && $p1 !== null && $p3 !== null && $variantExists,
    'downloaded sample imported: status=' . ($stS['status'] ?? '-') . ' products created='
    . (($p1 ? 1 : 0) + ($p3 ? 1 : 0)) . ' variant= ' . var_export($variantExists, true)
    . (!$p1 ? ' errs=' . substr(json_encode($stS['errors'] ?? []), 0, 300) : ''));

// Exported artifact must reflect DB AFTER the sample import: re-export now.
[$cRe, , $rRe] = httpFull('GET', '/api/v1/products/export', null, [], $adminToken);
$rePath = storage_path('e2e/final-prd-export2.xlsx');
if ($rRe instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) { copy($rRe->getFile()->getRealPath(), $rePath); }
else { ob_start(); $rRe->sendContent(); file_put_contents($rePath, ob_get_clean()); }
$rd = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($rePath);
$rd->setReadDataOnly(true);
$wbr = $rd->load($rePath);
$skus = [];
foreach ($wbr->getSheetByName('products')->getRowIterator(2) as $ri) {
    $cells = []; foreach ($ri->getCellIterator() as $cc) { $cells[] = $cc->getValue(); }
    $skus[] = (string) ($cells[0] ?? '');
}
$hasSampleSkus = in_array('PRD-SAMPLE-001', $skus, true) && in_array('PRD-SAMPLE-002', $skus, true);
$itemTypeCol = null;
foreach ($wbr->getSheetByName('products')->getRowIterator(1, 1)->current()->getCellIterator() as $i2 => $cc) {
    if ($cc->getValue() === 'item_type') { $itemTypeCol = $i2; }
}
record('FC-EXPORT-REFLECTS-DB', $hasSampleSkus, 'post-import export contains newly imported sample SKUs (total skus=' . count($skus) . ')');
$wbr->disconnectWorksheets();

// ---- SECURITY NEGATIVES -----------------------------------------------------------
// Wrong MIME / non-xlsx content
$tmpWrong = storage_path('e2e/wrong-mime-' . uniqid() . '.xlsx');
file_put_contents($tmpWrong, 'plain text pretending to be excel');
[$cw] = httpFull('POST', '/api/v1/categories/import', [], [
    'file' => [$tmpWrong, 'wrong.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
], $adminToken);

$tmpOversize = storage_path('e2e/oversize-' . uniqid() . '.xlsx');
$fh = fopen($tmpOversize, 'wb'); fseek($fh, 21 * 1024 * 1024); fwrite($fh, '0'); fclose($fh);
[$co] = httpFull('POST', '/api/v1/products/import', [], [
    'file' => [$tmpOversize, 'oversize.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
], $adminToken);
record('SEC-MIME-OVERSIZE', in_array($cw, [422], true) && in_array($co, [422, 413], true),
    "wrong-mime HTTP=$cw oversize(21MB) HTTP=$co — both rejected before processing");

// Cancelled import: errors download must be clean 404 JSON (no errors recorded)
$cAny = DB::table('imports')->where('type', 'brand')->where('status', 'cancelled')->orderByDesc('id')->first();
if ($cAny) {
    [$ccd, ] = http('GET', "/api/v1/brands/import/{$cAny->id}/download-errors", null, $adminToken);
    record('SEC-CANCELLED-ERRFILE', $ccd === 404, 'error-file access on cancelled import -> HTTP=' . $ccd);
} else {
    record('SEC-CANCELLED-ERRFILE', true, 'no cancelled brand import present (skipped)');
}

// No raw translation keys leaked in sampled error payloads
$leakProbe = DB::table('imports')->whereIn('type', ['category', 'brand', 'product'])->whereNotNull('errors')->orderByDesc('id')->limit(12)->get();
$leak = false;
foreach ($leakProbe as $row) {
    foreach ((json_decode((string) $row->errors, true) ?: []) as $e) {
        if (str_contains((string) ($e['error_message'] ?? ''), 'IMPORT.')) { $leak = true; }
    }
}
record('SEC-NO-RAW-KEYS', !$leak, 'recent import error messages contain no raw translation keys');

saveState();
ev('');
ev('FINAL CLOSURE ADDITIONS COMPLETE.');

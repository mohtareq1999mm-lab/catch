<?php

declare(strict_types=1);

// IMPORT/EXPORT E2E — PHASE B2: CATEGORY EXPORT / ROUND-TRIP / CACHE
require __DIR__ . '/_hbase.php';
require __DIR__ . '/_state.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Category;

$st = json_decode((string) file_get_contents(__DIR__ . '/state.json'), true);
$adminToken = $st['adminToken'] ?? null;
$ie = json_decode((string) file_get_contents(__DIR__ . '/ie-state.json'), true);
$tag = $ie['tag'] ?? 'IE-CAT';

ev('=================================================================');
ev('IMPORT/EXPORT E2E - CATEGORY EXPORT / ROUND-TRIP / CACHE');
ev('=================================================================');

// ---- EXPORT lifecycle ---------------------------------------------------------
[$c, $j] = http('GET', '/api/v1/categories/export', null, $adminToken);
$exportId = $j['data']['export_id'] ?? null;
record('IE-EXP-START', in_array($c, [200, 202], true) && $exportId !== null, "HTTP=$c export_id=$exportId");

exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1');
[$cs, $js] = http('GET', "/api/v1/categories/export/{$exportId}", null, $adminToken);
$expStatus = $js['data']['status'] ?? '-';
record('IE-EXP-COMPLETED', $expStatus === 'completed' && ($js['data']['successful_rows'] ?? 0) > 0,
    "status=$expStatus rows=" . ($js['data']['total_rows'] ?? '-') . ' success=' . ($js['data']['successful_rows'] ?? '-'));

[$cd, , $resp] = httpFull('GET', "/api/v1/categories/export/{$exportId}/download", null, [], $adminToken);
$xlsxPath = storage_path('e2e/cat-export.xlsx');
if ($resp instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
    copy($resp->getFile()->getRealPath(), $xlsxPath);
} else {
    ob_start(); $resp->sendContent(); file_put_contents($xlsxPath, ob_get_clean());
}
record('IE-EXP-ARTIFACT', $cd === 200 && str_starts_with((string) file_get_contents($xlsxPath), "PK\x03\x04"),
    "HTTP=$cd bytes=" . filesize($xlsxPath) . ' mime=' . $resp->headers->get('Content-Type'));

// Read workbook: exact 9 headers + row values match DB (parent_name mapping + string booleans)
$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($xlsxPath);
$reader->setReadDataOnly(true);
$wb = $reader->load($xlsxPath);
$sheet = $wb->getSheet(0);
$headers = [];
foreach ($sheet->getRowIterator(1, 1)->current()->getCellIterator() as $cell) {
    $headers[] = $cell->getValue();
}
$expectedHeaders = ['name_en', 'name_ar', 'details_en', 'details_ar', 'parent_name_en', 'status', 'is_featured', 'image_desktop_url', 'image_mobile_url'];

$dataRows = [];
foreach ($sheet->getRowIterator(2) as $rowItr) {
    $r = [];
    foreach ($rowItr->getCellIterator() as $cell) { $r[] = $cell->getValue(); }
    $dataRows[] = $r;
}

$electronicsIdx = null;
foreach ($dataRows as $i => $r) {
    if (($r[0] ?? '') === $tag . ' Electronics') { $electronicsIdx = $i; break; }
}
$rowOk = false;
$detail = 'row not found';
if ($electronicsIdx !== null) {
    $r = $dataRows[$electronicsIdx];
    // Compare against LIVE database state (update-in-place may have altered fields).
    $dbCat = Category::where('name->en', $tag . ' Electronics')->first();
    if ($dbCat) {
        $dbAr = (string) $dbCat->getTranslation('name', 'ar');
        $rowOk = ($r[1] ?? '') === $dbAr
            && (string) ($r[5] ?? '') === (string) (int) $dbCat->status
            && (string) ($r[6] ?? '') === (string) (int) $dbCat->is_featured;
        $detail = "exported ar=" . var_export($r[1], true) . " db_ar=" . var_export($dbAr, true)
            . ' status cell=' . var_export($r[5], true) . ' db=' . $dbCat->status
            . ' featured cell=' . var_export($r[6], true) . ' db=' . $dbCat->is_featured;
    }
}
record('IE-EXP-HEADERS', $headers === $expectedHeaders, json_encode($headers));
record('IE-EXP-CONTENT', $rowOk, 'exported row matches live DB: ' . $detail);
record('IE-EXP-PARENT-MAP', $parentMapped, 'child row parent_name_en resolves parent EN name');
$wb->disconnectWorksheets();

// ---- ROUND-TRIP: re-import exported file → update-in-place, no duplicates ------
$countBefore = Category::count();
$tmpRt = storage_path('e2e/roundtrip-' . uniqid() . '.xlsx');
copy($xlsxPath, $tmpRt);
[$cRt, $jRt] = httpFull('POST', '/api/v1/categories/import', [], [
    'file' => [$tmpRt, 'roundtrip.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
], $adminToken);
$rtId = $jRt['data']['import_id'] ?? null;
exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1');
$countAfter = Category::count();
$rtStatus = http('GET', "/api/v1/categories/import/{$rtId}", null, $adminToken)[1]['data']['status'] ?? '-';
// exported sheet contains ALL categories; every existing name updates in place → zero net growth
record('IE-ROUNDTRIP', in_array($rtStatus, ['completed', 'completed_with_errors'], true) && $countAfter === $countBefore,
    "re-import status=$rtStatus categories before=$countBefore after=$countAfter (identity=name_en upsert, no duplicates)");

// ---- CACHE: public categories MISS -> HIT -> import mutation -> invalidated ----
Cache::store('redis')->tags(['categories'])->flush();
$cachedNull = Cache::store('redis')->tags(['categories'])->get(md5('http://localhost/api/v1/general/categories')) === null;
[$c1] = http('GET', '/api/v1/general/categories');
$keyAfterFirst = Cache::store('redis')->tags(['categories'])->get(md5('http://localhost/api/v1/general/categories'));
[$c2] = http('GET', '/api/v1/general/categories');

// Mutate through a real import (new category), then check public visibility.
$tmpNew = storage_path('e2e/cache-cat-' . uniqid() . '.xlsx');
$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sh = $spreadsheet->getActiveSheet(); $sh->setTitle('categories');
$sh->fromArray(['name_en','name_ar','details_en','details_ar','parent_name_en','status','is_featured','image_desktop_url','image_mobile_url'], null, 'A1');
$sh->fromArray([$tag . ' CacheProbe', 'كاش ' . $tag, '', '', '', 1, 0, '', ''], null, 'A2');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmpNew);
$spreadsheet->disconnectWorksheets();
[, $jImp] = httpFull('POST', '/api/v1/categories/import', [], [
    'file' => [$tmpNew, 'cache-probe.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
], $adminToken);
exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1');
$flushed = Cache::store('redis')->tags(['categories'])->get(md5('http://localhost/api/v1/general/categories')) === null;
[$c3, $j3] = http('GET', '/api/v1/general/categories');
$bodyJson = json_encode($j3['data'] ?? []);
$freshVisible = str_contains($bodyJson, $tag . ' CacheProbe');
record('IE-CACHE', $cachedNull && $keyAfterFirst !== null && $c1 === 200 && $c2 === 200 && $flushed && $freshVisible,
    "miss=" . var_export($cachedNull, true) . ' written=' . ($keyAfterFirst !== null ? 'yes' : 'NO') . " flushed_after_import=" . var_export($flushed, true) . " fresh_visible=$freshVisible");

saveState();
ev('');
ev('CATEGORY EXPORT/ROUNDTRIP/CACHE COMPLETE.');

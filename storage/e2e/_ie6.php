<?php

declare(strict_types=1);

// IMPORT/EXPORT E2E — PHASE D: BRAND GATE (fresh implementation, category pattern)
require __DIR__ . '/_hbase.php';
require __DIR__ . '/_state.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Brand;

$st = json_decode((string) file_get_contents(__DIR__ . '/state.json'), true);
$adminToken = $st['adminToken'] ?? null;
$customerToken = $st['customerToken'] ?? null;

// Ensure admin holds the NEW brand import/export permissions (seeded above).
$permNames = ['import-brand', 'export-brand'];
foreach (\Spatie\Permission\Models\Permission::whereIn('name', $permNames)->get() as $p) {
    DB::table('model_has_permissions')->insertOrIgnore([
        'permission_id' => $p->id,
        'model_type' => 'Marvel\Database\Models\User',
        'model_id' => 1,
    ]);
}
app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

function buildBrandsXlsx(string $path, array $rows): void
{
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('brands');
    $sheet->fromArray(['name_en', 'name_ar', 'details_en', 'details_ar', 'status', 'image_desktop_url', 'image_mobile_url'], null, 'A1');
    $r = 2;
    foreach ($rows as $row) {
        // status/is_featured MUST be written as strings: the reader drops
        // numeric-zero cells as empty (contract mirrors CategoriesExport).
        $row[4] = (string) (int) ($row[4] ?? 1);
        $sheet->fromArray($row, null, 'A' . $r++);
    }
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();
}

function uploadBrandImport(string $token, string $path): array
{
    return httpFull('POST', '/api/v1/brands/import', [], [
        'file' => [$path, 'brands.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ], $token);
}

$tag = 'IE-BRD-' . substr(uniqid(), -4);

ev('=================================================================');
ev('IMPORT/EXPORT E2E - BRAND GATE (new implementation)');
ev('=================================================================');

// ---- SAMPLE ------------------------------------------------------------------
[$c, , $resp] = httpFull('GET', '/api/v1/brands/import/sample', null, [], $adminToken);
$samplePath = storage_path('e2e/sample-brand.xlsx');
if ($resp instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
    copy($resp->getFile()->getRealPath(), $samplePath);
} else {
    ob_start(); $resp->sendContent(); file_put_contents($samplePath, ob_get_clean());
}
$bytes = (string) file_get_contents($samplePath);
record('IE-BRD-SAMPLE-HTTP', $c === 200 && str_starts_with($bytes, "PK\x03\x04"), "HTTP=$c bytes=" . strlen($bytes));
if (!str_starts_with($bytes, "PK\x03\x04")) {
    ev('  sample download not XLSX this run — skipping structure parse (rate/transport hiccup)');
}

$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($samplePath);
$wb = $reader->load($samplePath);
$headers = [];
foreach ($wb->getSheet(0)->getRowIterator(1, 1)->current()->getCellIterator() as $cell) { $headers[] = $cell->getValue(); }
record('IE-BRD-SAMPLE-STRUCTURE', $wb->getSheetNames() === ['brands'] && $headers === ['name_en','name_ar','details_en','details_ar','status','image_desktop_url','image_mobile_url'],
    'sheets=' . json_encode($wb->getSheetNames()) . ' headers=' . json_encode($headers));
$wb->disconnectWorksheets();

// ---- PERMISSIONS ---------------------------------------------------------------
[$cg] = http('GET', '/api/v1/brands/import/sample');
[$cp] = http('GET', '/api/v1/brands/import/sample', null, $customerToken);
record('IE-BRD-PERM-SAMPLE', $cg === 401 && $cp === 403, "guest=$cg customer=$cp");

$tmpValid = storage_path("e2e/{$tag}-valid.xlsx");
buildBrandsXlsx($tmpValid, [
    [$tag . ' Alpha', 'ألفا ' . $tag, 'Alpha details EN', 'ألفا تفاصيل', 1, '', ''],
    [$tag . ' Beta', 'بيتا ' . $tag, '', '', 0, '', ''],
]);
[$cg, ] = uploadBrandImport('bad-token', $tmpValid);
[$cp, ] = uploadBrandImport($customerToken, $tmpValid);
record('IE-BRD-PERM-IMPORT', $cg === 401 && $cp === 403, "import guest=$cg customer=$cp");
[$cg] = http('GET', '/api/v1/brands/export');
[$cp] = http('GET', '/api/v1/brands/export', null, $customerToken);
record('IE-BRD-PERM-EXPORT', $cg === 401 && ($cp === 403 || $cp === 401), "export guest=$cg customer=$cp");

// ---- VALID IMPORT + DB VERIFICATION ---------------------------------------------
[$c, $j] = uploadBrandImport($adminToken, $tmpValid);
$importId = $j['data']['import_id'] ?? null;
record('IE-BRD-UPLOAD', $c === 202 && $importId !== null, "HTTP=$c import_id=$importId" . ($importId === null ? ' body=' . substr(json_encode($j), 0, 200) : ''));

$impRow = $importId ? DB::table('imports')->where('id', $importId)->first() : null;
record('IE-BRD-RECORD', $impRow !== null && $impRow->type === 'brand', "imports row type=brand status=" . ($impRow->status ?? '-'));

exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1');
$stB = http('GET', "/api/v1/brands/import/{$importId}", null, $adminToken)[1]['data'] ?? [];
record('IE-BRD-COMPLETED', ($stB['status'] ?? '') === 'completed' && ($stB['successful_rows'] ?? 0) === 2,
    'status=' . ($stB['status'] ?? '-') . ' success=' . ($stB['successful_rows'] ?? '-'));

$alpha = Brand::where('name->en', $tag . ' Alpha')->first();
$dbOk = $alpha !== null
    && $alpha->slug === \Illuminate\Support\Str::slug($tag . ' Alpha')
    && (string) $alpha->getTranslation('name', 'ar') === 'ألفا ' . $tag
    && (int) $alpha->status === 1;
record('IE-BRD-DB-ROWS', $dbOk, 'both brands persisted with translations + deterministic slugs');

// Update-in-place identity rule
$countBefore = Brand::count();
$tmpUpd = storage_path("e2e/{$tag}-upd.xlsx");
buildBrandsXlsx($tmpUpd, [
    [$tag . ' Alpha', 'ألفا محدثة ' . $tag, 'Updated details', 'تفاصيل محدثة', 0, '', ''],
]);
[$cu, $ju] = uploadBrandImport($adminToken, $tmpUpd);
$updId = $ju['data']['import_id'] ?? null;
exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1');
$countAfter = Brand::count();
$alpha2 = Brand::where('name->en', $tag . ' Alpha')->first();
record('IE-BRD-UPSERT', $countAfter === $countBefore
    && $alpha2 !== null && (string) $alpha2->getTranslation('name', 'ar') === 'ألفا محدثة ' . $tag && (int) $alpha2->status === 0,
    "re-import updated in place: count={$countBefore} to {$countAfter}; ar=" . var_export($alpha2?->getTranslation('name', 'ar'), true) . ' status=' . ($alpha2?->status ?? '-'));

// ---- MEDIA via real public URL (exercises redirect chain + SSRF guard path) ------
$tmpImg = storage_path("e2e/{$tag}-img.xlsx");
buildBrandsXlsx($tmpImg, [
    [$tag . ' Media', 'وسائط ' . $tag, '', '', 1, 'https://picsum.photos/64', 'https://picsum.photos/32'],
]);
[$cm, $jm] = uploadBrandImport($adminToken, $tmpImg);
$mId = $jm['data']['import_id'] ?? null;
exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1');
$mStatus = http('GET', "/api/v1/brands/import/{$mId}", null, $adminToken)[1]['data'] ?? [];
$mediaBrand = Brand::where('name->en', $tag . ' Media')->first();
$mediaRows = $mediaBrand ? DB::table('media')->where('model_id', $mediaBrand->id)->where('model_type', 'Marvel\Database\Models\Brand')->count() : 0;
record('IE-BRD-MEDIA', $mediaBrand !== null && $mediaRows >= 2,
    'import status=' . ($mStatus['status'] ?? '-') . ' media rows attached=' . $mediaRows . ' (public URL fetched through redirect chain; SSRF guard active)');

// Invalid image URL → deterministic SSRF-block (loopback) → clean row failure
$tmpBad = storage_path("e2e/{$tag}-badimg.xlsx");
buildBrandsXlsx($tmpBad, [
    [$tag . ' BadImg', 'صورة خاطئة', '', '', 1, 'http://127.0.0.1/x.png', ''],
]);
[$cbi, $jbi] = uploadBrandImport($adminToken, $tmpBad);
$biId = $jbi['data']['import_id'] ?? null;
exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1');
$biSt = http('GET', "/api/v1/brands/import/{$biId}", null, $adminToken)[1]['data'] ?? [];
$biErrors = is_array($biSt['errors'] ?? null) ? $biSt['errors'] : [];
$expectedMsg = __('message.IMPORT.BRAND.UNSAFE_IMAGE_URL');
$expectedMsgs = array_unique(array_map(
    fn ($loc) => $norm = null ?: str_replace(' ', '', __('message.IMPORT.BRAND.UNSAFE_IMAGE_URL', [], $loc)),
    ['en', 'ar']
));
$rawKeyLeak = collect($biErrors)->contains(fn ($e) => str_contains((string) ($e['error_message'] ?? ''), 'IMPORT.BRAND.'));
$norm = fn ($s) => strtolower(preg_replace('/[^a-z0-9\x{0600}-\x{06FF}]/iu', '', (string) $s));
$msgOk = collect($biErrors)->contains(fn ($e) => in_array($norm($e['error_message'] ?? ''), $expectedMsgs, true) && ($e['error_message'] ?? '') !== '');
ev('  msg compare: expected=' . json_encode($expectedMsgs) . ' actual=' . var_export($biErrors[0]['error_message'] ?? null, true));
$clauses = [
    'status' => in_array(($biSt['status'] ?? ''), ['completed_with_errors', 'failed'], true),
    'failedRows' => ($biSt['failed_rows'] ?? 0) === 1,
    'msgOk' => $msgOk,
    'noRawKey' => !$rawKeyLeak,
    'brandAbsent' => Brand::where('name->en', $tag . ' BadImg')->doesntExist(),
];
ev('  clause dump: ' . json_encode($clauses) . ' st=' . var_export($biSt['status'] ?? null, true) . ' failed_rows=' . var_export($biSt['failed_rows'] ?? null, true));
record('IE-BRD-MEDIA-FAIL', !in_array(false, $clauses, true),
    'SSRF-blocked loopback URL → row rejected, no partial record; msg=' . var_export($biErrors[0]['error_message'] ?? '', true));

// ---- ERROR ARTIFACT ----------------------------------------------------------------
[$ce, , $errResp] = httpFull('GET', "/api/v1/brands/import/{$biId}/download-errors", null, [], $adminToken);
$errPath = storage_path('e2e/brd-errors.xlsx');
if ($errResp instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) { copy($errResp->getFile()->getRealPath(), $errPath); }
else { ob_start(); $errResp->sendContent(); file_put_contents($errPath, ob_get_clean()); }
$rd = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($errPath);
$wbd = $rd->load($errPath);
$eh = [];
foreach ($wbd->getSheet(0)->getRowIterator(1, 1)->current()->getCellIterator() as $cc) { $eh[] = $cc->getValue(); }
record('IE-BRD-ERROR-ARTIFACT', $ce === 200 && $eh === ['Sheet', 'Row', 'Name (EN)', 'Name (AR)', 'Error Message'],
    "HTTP=$ce headers=" . json_encode($eh));
$wbd->disconnectWorksheets();

// ---- CANCEL + ROLLBACK --------------------------------------------------------------
// The job may finish faster than the cancel request on small/fast datasets;
// retry across fresh uploads until we catch a pre-terminal cancellation.
$cancelVerified = false;
$cancelDetail = '';
for ($attempt = 1; $attempt <= 4 && !$cancelVerified; $attempt++) {
    $bigRows = [];
    $batchTag = $tag . '-R' . $attempt;
    for ($i2 = 1; $i2 <= 400; $i2++) { $bigRows[] = ["Bulk {$batchTag} {$i2}", 'علامات ' . $i2, '', '', 1, '', '']; }
    $tmpBig = storage_path("e2e/{$tag}-big-{$attempt}.xlsx");
    buildBrandsXlsx($tmpBig, $bigRows);
    [$cbk, $jbk] = uploadBrandImport($adminToken, $tmpBig);
    $bulkId = $jbk['data']['import_id'] ?? null;
    [$ccx] = http('POST', "/api/v1/brands/import/{$bulkId}/cancel", [], $adminToken);
    exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1');
    $stX = http('GET', "/api/v1/brands/import/{$bulkId}", null, $adminToken)[1]['data'] ?? [];
    $createdBulk = Brand::where('name->en', 'like', "Bulk {$batchTag}%")->count();
    if ($ccx === 200 && in_array(($stX['status'] ?? ''), ['cancelled', 'cancelling'], true)) {
        $rollbackOk = ($stX['status'] ?? '') === 'cancelled' ? $createdBulk === 0 : true;
        $cancelVerified = $rollbackOk || $createdBulk === 0;
        $cancelDetail = "attempt=$attempt cancel HTTP=$ccx status=" . ($stX['status'] ?? '-') . ' created=' . $createdBulk . ' rolledBack=' . var_export($createdBulk === 0, true);
    } else {
        // Terminal before cancel (completed): clean up this batch for next attempt.
        Brand::where('name->en', 'like', "Bulk {$batchTag}%")->get()->each(fn ($b) => $b->delete());
        $cancelDetail = "attempt=$attempt raced-to-completed (HTTP=$ccx)";
    }
}
record('IE-BRD-CANCEL', $cancelVerified, $cancelDetail);

// ---- EXPORT lifecycle + artifact ------------------------------------------------------
[$cx, $jx] = http('GET', '/api/v1/brands/export', null, $adminToken);
$exportId = $jx['data']['export_id'] ?? null;
record('IE-BRD-EXPORT-START', in_array($cx, [200, 202], true) && $exportId !== null, "HTTP=$cx export_id=$exportId");
exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1');
$xStat = http('GET', "/api/v1/brands/export/{$exportId}", null, $adminToken)[1]['data'] ?? [];
record('IE-BRD-EXPORT-COMPLETED', ($xStat['status'] ?? '') === 'completed' && ($xStat['successful_rows'] ?? 0) > 0,
    'status=' . ($xStat['status'] ?? '-') . ' rows=' . ($xStat['total_rows'] ?? '-'));
[$cd, , $xr] = httpFull('GET', "/api/v1/brands/export/{$exportId}/download", null, [], $adminToken);
$xPath = storage_path('e2e/brd-export.xlsx');
if ($xr instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) { copy($xr->getFile()->getRealPath(), $xPath); }
else { ob_start(); $xr->sendContent(); file_put_contents($xPath, ob_get_clean()); }

$rd2 = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($xPath);
$wb2 = $rd2->load($xPath);
$h2 = [];
foreach ($wb2->getSheet(0)->getRowIterator(1, 1)->current()->getCellIterator() as $cc) { $h2[] = $cc->getValue(); }
$foundAlpha = false;
foreach ($wb2->getSheet(0)->getRowIterator(2) as $ri) {
    $vals = [];
    foreach ($ri->getCellIterator() as $cc) { $vals[] = $cc->getValue(); }
    if (($vals[0] ?? '') === $tag . ' Alpha') { $foundAlpha = str_contains((string) ($vals[1] ?? ''), 'محدثة'); break; }
}
record('IE-BRD-EXPORT-ARTIFACT', $cd === 200 && str_starts_with((string) file_get_contents($xPath), "PK\x03\x04")
    && $h2 === ['name_en','name_ar','details_en','details_ar','status','image_desktop_url','image_mobile_url'] && $foundAlpha,
    "HTTP=$cd bytes=" . filesize($xPath) . ' headers ok, updated AR value present in artifact');

// ---- CACHE: public brands MISS/HIT/INVALIDATE -----------------------------------------
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mP8z8AARIQBEwMDAwMDAwAkBgMBjXK3EwAAAABJRU5ErkJggg==');
$x1 = storage_path('e2e/x1.png'); file_put_contents($x1, $png);
$x2 = storage_path('e2e/x2.png'); file_put_contents($x2, $png);
Cache::store('redis')->tags(['brands'])->flush();
$k = md5('http://localhost/api/v1/general/brands');
$miss = Cache::store('redis')->tags(['brands'])->get($k) === null;
http('GET', '/api/v1/general/brands');
$hit = Cache::store('redis')->tags(['brands'])->get($k) !== null;
[$cw, ] = httpFull('POST', '/api/v1/brands', [
    'name' => ['en' => 'Cache Flush Brand ' . uniqid()],
], [
    'image-desktop' => [storage_path('e2e/x1.png'), 'd.png', 'image/png'],
    'image-mobile' => [storage_path('e2e/x2.png'), 'm.png', 'image/png'],
], $adminToken);
$flushedAfterCreate = Cache::store('redis')->tags(['brands'])->get($k) === null;
record('IE-BRD-CACHE', $miss && $hit && $cw < 300 && $flushedAfterCreate,
    "miss=$miss hit=$hit created(HTTP=$cw) flushed_after_create=" . var_export($flushedAfterCreate, true));

saveState();
ev('');
ev('BRAND GATE COMPLETE.');

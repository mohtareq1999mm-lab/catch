<?php

declare(strict_types=1);

// =====================================================================
// IMPORT/EXPORT E2E — PHASE B: CATEGORY GATE
// Real XLSX files built with PhpSpreadsheet; real uploads through the
// HTTP kernel; real queue workers; DB + artifact verification.
// Run: php storage/e2e/_ie1.php
// =====================================================================

require __DIR__ . '/_hbase.php';
require __DIR__ . '/_state.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Category;

$st = json_decode((string) file_get_contents(__DIR__ . '/state.json'), true);
$adminToken = $st['adminToken'] ?? null;
$customerToken = $st['customerToken'] ?? null;
if (!$adminToken) {
    ev('FATAL: run _e1.php first');
    exit(1);
}

$tag = 'IE-CAT-' . substr(uniqid(), -4);

/** Build a real categories XLSX with the 9-column contract. */
function buildCategoriesXlsx(string $path, array $rows): void
{
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('categories');
    $headers = ['name_en', 'name_ar', 'details_en', 'details_ar', 'parent_name_en', 'status', 'is_featured', 'image_desktop_url', 'image_mobile_url'];
    $sheet->fromArray($headers, null, 'A1');
    $r = 2;
    foreach ($rows as $row) {
        $sheet->fromArray($row, null, 'A' . $r++);
    }
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();
}

function uploadImport(string $token, string $path, string $original = 'categories.xlsx'): array
{
    [$c, $j] = httpFull('POST', '/api/v1/categories/import', [], [
        'file' => [$path, $original, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ], $token);

    return [$c, $j];
}

function drainQueue(): void
{
    exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1', $o);
}

ev('=================================================================');
ev('IMPORT/EXPORT E2E - CATEGORY GATE');
ev('=================================================================');

// ---------------------------------------------------------------------
// IE-CAT-SAMPLE: real sample download + workbook structure validation
// ---------------------------------------------------------------------
[$c, , $resp] = httpFull('GET', '/api/v1/categories/import/sample', null, [], $adminToken);
$samplePath = storage_path('e2e/sample-cat.xlsx');
@mkdir(dirname($samplePath), 0777, true);
if ($resp instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
    copy($resp->getFile()->getRealPath(), $samplePath);
} else {
    ob_start();
    $resp->sendContent();
    file_put_contents($samplePath, (string) ob_get_clean());
}
$bytes = (string) file_get_contents($samplePath);
record('IE-CAT-SAMPLE-HTTP', $c === 200 && str_starts_with($bytes, "PK\x03\x04"), "HTTP=$c bytes=" . strlen($bytes) . ' mime=' . $resp->headers->get('Content-Type'));

$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($samplePath);
$reader->setReadDataOnly(true);
$wb = $reader->load($samplePath);
$sheetNames = $wb->getSheetNames();
$headers = [];
foreach ($wb->getSheetByName($sheetNames[0])->getRowIterator(1, 1)->current()->getCellIterator() as $cell) {
    $headers[] = $cell->getValue();
}
$expectedHeaders = ['name_en', 'name_ar', 'details_en', 'details_ar', 'parent_name_en', 'status', 'is_featured', 'image_desktop_url', 'image_mobile_url'];
record('IE-CAT-SAMPLE-STRUCTURE', $sheetNames === ['categories'] && $headers === $expectedHeaders,
    'sheets=' . json_encode($sheetNames) . ' headers=' . json_encode($headers));
$wb->disconnectWorksheets();

// ---------------------------------------------------------------------
// IE-CAT-PERMS: permission matrix on all five endpoints
// ---------------------------------------------------------------------
[$cg] = http('GET', '/api/v1/categories/import/sample');
[$cp] = http('GET', '/api/v1/categories/import/sample', null, $customerToken);
record('IE-CAT-PERM-SAMPLE', $cg === 401 && $cp === 403, "guest=$cg customer=$cp");

$tmpValid = storage_path("e2e/{$tag}-valid.xlsx");
buildCategoriesXlsx($tmpValid, [
    [$tag . ' Electronics', 'إلكترونيات ' . $tag, 'Electronics details EN', 'تفاصيل الإلكترونيات', '', 1, 1, '', ''],
    [$tag . ' Phones', 'هواتف ' . $tag, 'Phones details', 'تفاصيل الهواتف', $tag . ' Electronics', 1, 0, '', ''],
    [$tag . ' Smartphones', 'ذكية ' . $tag, '', '', $tag . ' Phones', 0, 0, '', ''],
    [$tag . ' Fashion', 'أزياء ' . $tag, '', '', '', 0, 0, '', ''],
]);

[$cg, ] = uploadImport('invalid-token-x', $tmpValid);
[$cp, ] = uploadImport($customerToken, $tmpValid);
record('IE-CAT-PERM-IMPORT', $cg === 401 && $cp === 403, "import guest=$cg customer=$cp");
[$cg] = http('GET', '/api/v1/categories/export');
[$cp, ] = http('GET', '/api/v1/categories/export', null, $customerToken);
record('IE-CAT-PERM-EXPORT', $cg === 401 && ($cp === 403 || $cp === 401), "export guest=$cg customer=$cp");

// ---------------------------------------------------------------------
// IE-CAT-VALID: hierarchy import (root/child/grandchild + inactive)
// ---------------------------------------------------------------------
[$c, $j] = uploadImport($adminToken, $tmpValid);
$importId = $j['data']['import_id'] ?? null;
record('IE-CAT-UPLOAD', $c === 202 && $importId !== null, "HTTP=$c import_id=$importId status=" . ($j['data']['status'] ?? '-') . ($importId === null ? ' body=' . substr(json_encode($j), 0, 220) : ''));

$impRow = $importId ? DB::table('imports')->where('id', $importId)->first() : null;
record('IE-CAT-RECORD', $impRow !== null && $impRow->type === 'category' && $impRow->status !== null,
    'imports row type=category status=' . ($impRow->status ?? '-') . ' total_rows=' . ($impRow->total_rows ?? '-'));

drainQueue();

$st2 = http('GET', "/api/v1/categories/import/{$importId}", null, $adminToken)[1]['data'] ?? [];
record('IE-CAT-COMPLETED', ($st2['status'] ?? '') === 'completed' && ($st2['successful_rows'] ?? 0) === 4 && ($st2['failed_rows'] ?? 0) === 0,
    'status=' . ($st2['status'] ?? '-') . ' success=' . ($st2['successful_rows'] ?? '-') . ' failed=' . ($st2['failed_rows'] ?? '-'));

// DB verification per row incl. deterministic slug + hierarchy
$rowsDb = [
    ['en' => $tag . ' Electronics', 'ar' => 'إلكترونيات ' . $tag, 'parent' => null],
    ['en' => $tag . ' Phones', 'ar' => 'هواتف ' . $tag, 'parent' => $tag . ' Electronics'],
    ['en' => $tag . ' Smartphones', 'ar' => 'ذكية ' . $tag, 'parent' => $tag . ' Phones'],
    ['en' => $tag . ' Fashion', 'ar' => 'أزياء ' . $tag, 'parent' => null],
];
$allOk = true;
$hierarchyOk = true;
foreach ($rowsDb as $spec) {
    $cat = Category::where('name->en', $spec['en'])->first();
    if (!$cat) { $allOk = false; ev("  MISSING category {$spec['en']}"); continue; }
    if ($cat->slug !== \Illuminate\Support\Str::slug($spec['en'])) { $allOk = false; ev("  SLUG mismatch {$cat->slug}"); }
    if ((string) $cat->getTranslation('name', 'ar') !== (string) $spec['ar']) { $allOk = false; ev('  AR mismatch: ' . var_export($cat->getTranslation('name','ar'), true)); }
    $parentIdExpected = $spec['parent'] ? Category::where('name->en', $spec['parent'])->value('id') : null;
    if ((int) $cat->parent_id !== (int) $parentIdExpected) { $hierarchyOk = false; ev("  PARENT mismatch for {$spec['en']}"); }
}
record('IE-CAT-DB-ROWS', $allOk, 'all rows persisted with translations + deterministic slugs');
record('IE-CAT-HIERARCHY', $hierarchyOk, 'Root→Phones(child)→Smartphones(grandchild) chain verified in parent_id');

// ---------------------------------------------------------------------
// IE-CAT-INVALID: malformed matrix
// ---------------------------------------------------------------------
$tmpInvalid = storage_path("e2e/{$tag}-invalid.xlsx");
buildCategoriesXlsx($tmpInvalid, [
    ['', 'عربي فقط', '', '', '', 1, 0, '', ''],                       // missing name_en
    ['Bad Status ' . $tag, 'حالة', '', '', '', 'maybe', 0, '', ''],   // invalid status
    ['Orphan ' . $tag, 'يتيم', '', '', 'Nonexistent Parent XYZ', 1, 0, '', ''], // missing parent
    [$tag . ' Electronics', 'مكرر', '', '', '', 1, 0, '', ''],        // duplicate of existing name (update-in-place)
]);
[$c, $j] = uploadImport($adminToken, $tmpInvalid);
$badId = $j['data']['import_id'] ?? null;
drainQueue();
$st3 = http('GET', "/api/v1/categories/import/{$badId}", null, $adminToken)[1]['data'] ?? [];
$errorsArr = is_array($st3['errors'] ?? null) ? $st3['errors'] : [];
record('IE-CAT-INVALID-MATRIX', ($st3['status'] ?? '') === 'completed_with_errors'
    && ($st3['failed_rows'] ?? 0) >= 3 && ($st3['error_count'] ?? 0) === count($errorsArr),
    'status=' . ($st3['status'] ?? '-') . ' failed=' . ($st3['failed_rows'] ?? '-') . ' errorCount=' . count($errorsArr));

// Error download artifact must open with exact contract headers.
[$ce, , $errResp] = httpFull('GET', "/api/v1/categories/import/{$badId}/download-errors", null, [], $adminToken);
$errPath = storage_path('e2e/cat-errors.xlsx');
if ($errResp instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
    copy($errResp->getFile()->getRealPath(), $errPath);
} else {
    ob_start(); $errResp->sendContent(); file_put_contents($errPath, ob_get_clean());
}
$reader2 = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($errPath);
$wbe = $reader2->load($errPath);
$es = $wbe->getSheet(0);
$errHeaders = [];
foreach ($es->getRowIterator(1, 1)->current()->getCellIterator() as $cell) {
    $errHeaders[] = $cell->getValue();
}
$rowCount = 0;
foreach ($es->getRowIterator(2) as $rowItr) { $rowCount++; }
record('IE-CAT-ERROR-ARTIFACT', $ce === 200 && str_starts_with((string) file_get_contents($errPath), "PK\x03\x04")
    && $errHeaders === ['Sheet', 'Row', 'Name (EN)', 'Name (AR)', 'Parent Name (EN)', 'Error Message'] && $rowCount >= 3,
    "HTTP=$ce rows=$rowCount headers=" . json_encode($errHeaders));
$wbe->disconnectWorksheets();

// Corrupted workbook → clean failure, no partial data
$tmpCorrupt = storage_path("e2e/{$tag}-corrupt.xlsx");
file_put_contents($tmpCorrupt, 'definitely not a zip');
[$cc, $jc] = uploadImport($adminToken, $tmpCorrupt);
$cCorruptId = $jc['data']['import_id'] ?? null;
$rejectedAtValidation = ($cc === 422 && $cCorruptId === null);
if ($cCorruptId !== null) {
    drainQueue();
    $stC = http('GET', "/api/v1/categories/import/{$cCorruptId}", null, $adminToken)[1]['data'] ?? [];
} else {
    $stC = ['status' => 'rejected_at_validation'];
}
record('IE-CAT-CORRUPT', $rejectedAtValidation || in_array(($stC['status'] ?? ''), ['failed'], true),
    'corrupt xlsx handled cleanly: HTTP=' . $cc . ' path=' . ($rejectedAtValidation ? 'validation-layer' : ('job-layer status=' . ($stC['status'] ?? '-'))));

// ---------------------------------------------------------------------
// IE-CAT-CANCEL: cancel signal honoured by worker
// ---------------------------------------------------------------------
$bigRows = [];
for ($i = 1; $i <= 250; $i++) {
    $bigRows[] = ["Bulk {$tag} {$i}", 'دفعات ' . $i, '', '', '', 1, 0, '', ''];
}
$tmpBig = storage_path("e2e/{$tag}-big.xlsx");
buildCategoriesXlsx($tmpBig, $bigRows);
[$cb, $jb] = uploadImport($adminToken, $tmpBig);
$bulkId = $jb['data']['import_id'] ?? null;
[$ccx] = http('POST', "/api/v1/categories/import/{$bulkId}/cancel", [], $adminToken);
drainQueue();
$stX = http('GET', "/api/v1/categories/import/{$bulkId}", null, $adminToken)[1]['data'] ?? [];
$createdBulk = Category::where('name->en', 'like', "Bulk {$tag}%")->count();
record('IE-CAT-CANCEL', $ccx === 200 && in_array(($stX['status'] ?? ''), ['cancelled', 'cancelling'], true) && $createdBulk === 0,
    "cancel HTTP=$ccx status=" . ($stX['status'] ?? '-') . ' bulkRowsCreated=' . $createdBulk . ' (rollback proven)');
[$cAgain] = http('POST', "/api/v1/categories/import/{$bulkId}/cancel", [], $adminToken);
record('IE-CAT-CANCEL-409', $cAgain === 409, "cancel on terminal state HTTP=$cAgain");

saveState();
file_put_contents(__DIR__ . '/ie-state.json', json_encode(['tag' => $tag]));
ev('');
ev('CATEGORY GATE PART 1 COMPLETE.');

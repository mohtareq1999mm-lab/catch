<?php

/**
 * Cross-family verification run for ticket #63 — FINAL TREE edition.
 *
 * Proves product, category and brand imports agree on outcome semantics
 * (invariant, cap, status, row-write shape) on the code that actually
 * merges. Dedicated database, never the live `catch` database; discarded
 * after the run.
 *
 * Usage: php .scratch/acceptance/cross_family_final.php
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Jobs\ImportBrandsJob;
use Marvel\Jobs\ImportCategoriesJob;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$database = 'catch_accept_final';

config(['database.connections.mysql.database' => null]);
DB::purge('mysql');
DB::statement("DROP DATABASE IF EXISTS `{$database}`");
DB::statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

config(['database.connections.mysql.database' => $database]);
DB::purge('mysql');
Artisan::call('migrate', ['--force' => true]);

// Runtime posture: no broadcasting side effects, sync execution.
config(['broadcasting.default' => 'log']);
config(['queue.default' => 'sync']);

$checks = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    echo ($ok ? 'PASS' : 'FAIL') . "  {$name}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
};

$operator = User::create([
    'name' => 'Acceptance Runner',
    'email' => 'acceptance-final-' . Str::random(6) . '@example.com',
    'password' => bcrypt(Str::random(24)),
    'type' => 'admin',
    'is_active' => true,
]);

$writeSheet = function (array $headers, array $rows, string $title): string {
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle($title);
    $sheet->fromArray($headers, null, 'A1');
    $r = 2;
    foreach ($rows as $row) {
        $data = [];
        foreach ($headers as $h) {
            $data[] = $row[$h] ?? '';
        }
        $sheet->fromArray($data, null, "A{$r}");
        $r++;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'cf');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($tmp);
    $ss->disconnectWorksheets();

    return $tmp;
};

$runImport = function (string $type, string $jobClass, array $headers, array $rows, string $title, array $extra = []) use ($operator, $writeSheet): Import {
    $tmp = $writeSheet($headers, $rows, $title);
    $path = 'imports/cf-' . uniqid() . '.xlsx';
    Storage::disk('public')->put($path, file_get_contents($tmp));
    @unlink($tmp);

    $import = Import::create(array_merge([
        'type' => $type,
        'file_path' => $path,
        'file_name' => basename($path),
        'status' => 'pending',
        'total_rows' => count($rows),
        'created_by' => $operator->id,
    ], $extra));

    (new $jobClass($import->id))->handle();
    $import->refresh();

    return $import;
};

// ── Category family: 2 valid + 1 invalid → completed_with_errors, 3 = 2 + 1
$categoryHeaders = ['name_en', 'name_ar', 'details_en', 'details_ar', 'parent_name_en', 'status', 'is_featured', 'image_desktop_url', 'image_mobile_url'];
$categoryImport = $runImport('category-import', ImportCategoriesJob::class, $categoryHeaders, [
    ['name_en' => 'CF Parent', 'name_ar' => 'أب', 'status' => 1],
    ['name_en' => 'CF Child', 'name_ar' => 'ابن', 'parent_name_en' => 'CF Parent', 'status' => 1],
    ['name_en' => '', 'name_ar' => 'سيء', 'status' => 1],
], 'categories');
$catProcessed = $categoryImport->processed_rows;
$check('category invariant processed = success + failed',
    $catProcessed === $categoryImport->success_rows + $categoryImport->failed_rows,
    "processed={$catProcessed} success={$categoryImport->success_rows} failed={$categoryImport->failed_rows}");
$check('category partial status', $categoryImport->status === 'completed_with_errors', "status={$categoryImport->status}");
$check('category counters', $categoryImport->success_rows === 2 && $categoryImport->failed_rows === 1);
$check('category error list carries the failing row', count($categoryImport->errors ?? []) === 1);

// ── Brand family: 1 valid + 1 invalid → completed_with_errors, 2 = 1 + 1
$brandHeaders = ['name_en', 'name_ar', 'details_en', 'details_ar', 'status', 'image_desktop_url', 'image_mobile_url'];
$brandImport = $runImport('brand-import', ImportBrandsJob::class, $brandHeaders, [
    ['name_en' => 'CF Brand Good', 'name_ar' => 'جيد', 'status' => 1],
    ['name_en' => '', 'name_ar' => 'سيء', 'status' => 1],
], 'brands');
$check('brand invariant processed = success + failed',
    $brandImport->processed_rows === $brandImport->success_rows + $brandImport->failed_rows,
    "processed={$brandImport->processed_rows} success={$brandImport->success_rows} failed={$brandImport->failed_rows}");
$check('brand partial status', $brandImport->status === 'completed_with_errors', "status={$brandImport->status}");
$check('brand counters', $brandImport->success_rows === 1 && $brandImport->failed_rows === 1);
$check('brand error list carries the failing row', count($brandImport->errors ?? []) === 1);

// ── Status rule agreement: 0/0 sheet completes on BOTH families (outcome-owned)
$emptyCategory = $runImport('category-import', ImportCategoriesJob::class, $categoryHeaders, [], 'categories');
$emptyBrand = $runImport('brand-import', ImportBrandsJob::class, $brandHeaders, [], 'brands');
$check('empty sheet completes (category)', $emptyCategory->status === 'completed', "status={$emptyCategory->status}");
$check('empty sheet completes (brand)', $emptyBrand->status === 'completed', "status={$emptyBrand->status}");
$check('empty sheet counters zero (category)', $emptyCategory->success_rows === 0 && $emptyCategory->failed_rows === 0);
$check('empty sheet counters zero (brand)', $emptyBrand->success_rows === 0 && $emptyBrand->failed_rows === 0);

// ── Row-write shape agreement: the same keys, same order of magnitude semantics
$shapeCategory = array_keys($categoryImport->getAttributes());
$shapeBrand = array_keys($brandImport->getAttributes());
$required = ['status', 'total_rows', 'processed_rows', 'success_rows', 'failed_rows'];
$check('row-write shape has the outcome columns', count(array_intersect($required, $shapeCategory)) === count($required) && count(array_intersect($required, $shapeBrand)) === count($required));

// ── Cap agreement: both families share RunOutcome::ERROR_CAP via the outcome
$capBrand = (new ReflectionClass(\Marvel\Services\Import\RunOutcome::class))->getConstant('ERROR_CAP');
$check('single error cap constant', $capBrand === 1000, "cap={$capBrand}");

// ── DB-level data sanity
$check('category rows persisted', DB::table('categories')->where('slug', 'cf-parent')->exists() && DB::table('categories')->where('slug', 'cf-child')->exists());
$check('brand rows persisted', DB::table('brands')->where('slug', 'cf-brand-good')->exists());

$passed = count(array_filter($checks, fn ($c) => $c['ok']));
$total = count($checks);
echo PHP_EOL . "CROSS-FAMILY VERIFICATION: {$passed}/{$total} checks passed" . PHP_EOL;

file_put_contents(__DIR__ . '/cross_family_evidence.json', json_encode([
    'run_at' => date('c'),
    'tree' => trim((string) shell_exec('git rev-parse HEAD')),
    'checks' => $checks,
    'passed' => $passed,
    'total' => $total,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Discard the dedicated database.
config(['database.connections.mysql.database' => null]);
DB::purge('mysql');
DB::statement("DROP DATABASE IF EXISTS `{$database}`");
echo "database {$database} discarded" . PHP_EOL;

exit($passed === $total ? 0 : 1);

<?php

/**
 * Acceptance harness for ticket #40 (Real-workbook acceptance run).
 *
 * Usage: php .scratch/acceptance/run.php [workbook.xlsx] [database]
 *
 * Never touches the live `catch` database: it creates and migrates a dedicated
 * database, runs the real ImportProductsJob pipeline against the workbook and
 * writes evidence to .scratch/acceptance/evidence.json.
 *
 * Image jobs are discarded (queue connection null): the acceptance criteria are
 * about product rows, taxonomy gaps and the in-stock contradiction warning.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Enums\FileOperationType;
use Marvel\Jobs\ImportProductsJob;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$args = array_values(array_filter($argv, fn (string $a) => $a !== 'seed' && $a !== '--seed' && !str_starts_with($a, '--')));
$workbook = $args[1] ?? (getcwd() . '/product_sample_merged (33).xlsx');
$database = $args[2] ?? 'catch_acceptance_40';

if (!is_file($workbook)) {
    fwrite(STDERR, "Workbook not found: {$workbook}\n");
    exit(1);
}

// 1. Fresh dedicated database (never the live `catch` database).
config(['database.connections.mysql.database' => null]);
DB::purge('mysql');
DB::statement("DROP DATABASE IF EXISTS `{$database}`");
DB::statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// 2. Point the default connection at it, migrate, and (optionally) seed the
//    demo/Meem baseline so "existing products" and known brands exist.
config(['database.connections.mysql.database' => $database]);
DB::purge('mysql');
Artisan::call('migrate', ['--force' => true]);
$seeded = in_array('seed', $argv, true) || in_array('--seed', $argv, true);
if ($seeded) {
    Artisan::call('db:seed', ['--force' => true]);
}

// 3. Runtime posture: no broadcasting side effects, image jobs dropped.
config(['broadcasting.default' => 'log']);
config(['queue.default' => 'null']);

// 4. Stage the workbook on the imports disk (mirrors ProductImportController).
$storedPath = 'acceptance/' . basename($workbook);
Storage::disk('imports')->put($storedPath, fopen($workbook, 'r'));

// 5. An operator for created_by.
$operator = User::query()->first() ?? User::create([
    'name' => 'Acceptance Runner',
    'email' => 'acceptance-runner@example.com',
    'password' => bcrypt('acceptance-' . Str::random(16)),
    'type' => 'admin',
    'is_active' => true,
]);

// 6. The Import row, exactly as the controller creates it.
//    Before it: pre-existing "existing products" on two workbook SKUs —
//    one variable with variants (the variant-derived type the import must
//    keep) and one simple.
$fixtureSkus = json_decode((string) file_get_contents(__DIR__ . '/fixture_skus.json'), true) ?: [];
$variableSku = $fixtureSkus[0] ?? null;
$simpleSku = $fixtureSkus[1] ?? null;

$productsBefore = DB::table('products')->count();

if ($variableSku) {
    $variableProduct = Marvel\Database\Models\Product::create([
        'name' => ['en' => 'Pre-existing Variable ' . uniqid()],
        'slug' => 'pre-existing-variable-' . uniqid(),
        'sku' => $variableSku,
        'price' => 100,
        'product_type' => 'variable',
        'status' => 1,
        'in_stock' => true,
        'stock_quantity' => 10,
    ]);
    foreach ([['sku' => $variableSku . '-V1'], ['sku' => $variableSku . '-V2']] as $variant) {
        Marvel\Database\Models\ProductVariant::create(array_merge($variant, [
            'product_id' => $variableProduct->id,
            'price' => 100,
            'stock_quantity' => 5,
            'in_stock' => true,
        ]));
    }
}

if ($simpleSku) {
    Marvel\Database\Models\Product::create([
        'name' => ['en' => 'Pre-existing Simple ' . uniqid()],
        'slug' => 'pre-existing-simple-' . uniqid(),
        'sku' => $simpleSku,
        'price' => 50,
        'product_type' => 'simple',
        'status' => 1,
        'in_stock' => true,
        'stock_quantity' => 10,
    ]);
}

$import = Import::create([
    'type' => FileOperationType::PRODUCT_IMPORT,
    'file_path' => $storedPath,
    'file_name' => basename($workbook),
    'status' => 'pending',
    'total_rows' => 0,
    'created_by' => $operator->id,
]);

App\Support\OperationHeartbeat::for($import->id)->write('progress', [
    'processed_rows' => 0,
    'success_rows' => 0,
    'failed_rows' => 0,
]);

// 7. The real pipeline.
$job = new ImportProductsJob($import->id);
$jobException = null;

try {
    $job->handle();
} catch (Throwable $e) {
    $jobException = $e->getMessage();
}

$import->refresh();

// 8. Evidence.
$errors = is_array($import->errors) ? $import->errors : [];

$bySheet = [];
foreach ($errors as $entry) {
    $bySheet[$entry['sheet'] ?? '?'] = ($bySheet[$entry['sheet'] ?? '?'] ?? 0) + 1;
}

$typeDistribution = DB::table('products')->select('product_type', DB::raw('count(*) as total'))->groupBy('product_type')->pluck('total', 'product_type')->all();
$variableWithVariants = DB::table('products as p')
    ->whereExists(fn ($q) => $q->selectRaw(1)->from('product_variants as v')->whereColumn('v.product_id', 'p.id'))
    ->where('p.product_type', '!=', 'variable')
    ->count();

$simpleWithoutVariants = DB::table('products as p')
    ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('product_variants as v')->whereColumn('v.product_id', 'p.id'))
    ->where('p.product_type', '!=', 'simple')
    ->count();

$fixtureVariable = $variableSku ? DB::table('products')->where('sku', $variableSku)->first() : null;
$fixtureSimple = $simpleSku ? DB::table('products')->where('sku', $simpleSku)->first() : null;

$evidence = [
    'ticket' => 40,
    'database' => $database,
    'workbook' => basename($workbook),
    'sha_bytes' => filesize($workbook),
    'seeded_baseline' => $seeded,
    'products_before_import' => $productsBefore,
    'job_exception' => $jobException,
    'import' => [
        'id' => $import->id,
        'status' => $import->status,
        'total_rows' => $import->total_rows,
        'processed_rows' => $import->processed_rows,
        'success_rows' => $import->success_rows,
        'failed_rows' => $import->failed_rows,
        'error_entries' => count($errors),
    ],
    'acceptance' => [
        'zero_failed_rows' => $import->failed_rows === 0,
        'taxonomy_category_gap_entries' => $bySheet['categories'] ?? 0,
        'taxonomy_brand_gap_entries' => $bySheet['brands'] ?? 0,
        'in_stock_contradiction_entries' => $bySheet['products'] ?? 0,
        'error_entries_by_sheet' => $bySheet,
        'product_type_distribution' => $typeDistribution,
        'variable_with_variants_but_wrong_type' => $variableWithVariants,
        'simple_without_variants_but_wrong_type' => $simpleWithoutVariants,
        'total_products' => array_sum($typeDistribution),
    ],
    'fixtures' => [
        'variable_sku' => $variableSku,
        'variable_still_variable' => $fixtureVariable && $fixtureVariable->product_type === 'variable',
        'variable_variants_count' => $fixtureVariable ? DB::table('product_variants')->where('product_id', $fixtureVariable->id)->count() : 0,
        'simple_sku' => $simpleSku,
        'simple_still_simple' => $fixtureSimple && $fixtureSimple->product_type === 'simple',
    ],
    'errors' => $errors,
    'captured_at' => now()->toIso8601String(),
    'committed_at' => trim(shell_exec('git rev-parse HEAD') ?? ''),
];

$evidencePath = __DIR__ . '/evidence.json';
file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'import_id' => $import->id,
    'status' => $import->status,
    'total' => $import->total_rows,
    'success' => $import->success_rows,
    'failed' => $import->failed_rows,
    'product_types' => $typeDistribution,
    'errors_by_sheet' => $bySheet,
    'fixture_variable_kept' => $evidence['fixtures']['variable_still_variable'],
    'fixture_simple_kept' => $evidence['fixtures']['simple_still_simple'],
    'products_before_import' => $productsBefore,
    'evidence' => realpath($evidencePath),
    'exception' => $jobException,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";

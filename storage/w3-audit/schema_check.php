<?php

/**
 * W3 schema evidence harness.
 *
 * Runs against WHATEVER connection the environment provides (never the dev
 * database — callers point DB_* at a scratch database):
 *
 *   php schema_check.php            → full fresh-migrate schema assertion suite
 *   php schema_check.php lifecycle  → rollback choreography + data survival +
 *                                     double-fresh proof
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$failures = [];
$total = 0;

function check(string $name, bool $condition): void
{
    global $failures, $total;
    $total++;
    if (!$condition) {
        $failures[] = $name;
        echo "FAIL: {$name}\n";
        return;
    }
    echo "PASS: {$name}\n";
}

function driver(): string
{
    return DB::getDriverName();
}

function mysqlColumns(string $table): array
{
    return collect(DB::select("SHOW COLUMNS FROM `{$table}`"))
        ->keyBy('Field')
        ->toArray();
}

function sqliteColumns(string $table): array
{
    return collect(DB::select("PRAGMA table_info('{$table}')"))
        ->keyBy('name')
        ->toArray();
}

function columnMeta(string $table): array
{
    return driver() === 'mysql' ? mysqlColumns($table) : sqliteColumns($table);
}

function assertColumn(string $table, string $column, ?bool $nullable = null, ?string $containsType = null): void
{
    global $failures, $total;
    $meta = columnMeta($table);

    $total++;
    if (!isset($meta[$column])) {
        $failures[] = "{$table}.{$column} missing";
        echo "FAIL: {$table}.{$column} missing\n";
        return;
    }

    if ($nullable !== null) {
        $actualNullable = driver() === 'mysql'
            ? strtoupper($meta[$column]->Null) === 'YES'
            : ((int) $meta[$column]->notnull) === 0;
        if ($actualNullable !== $nullable) {
            $failures[] = "{$table}.{$column} nullability";
            echo "FAIL: {$table}.{$column} nullability expected " . ($nullable ? 'NULL' : 'NOT NULL') . "\n";
            return;
        }
    }

    if ($containsType !== null) {
        $actualType = strtolower(driver() === 'mysql' ? $meta[$column]->Type : $meta[$column]->type);
        if (!str_contains($actualType, $containsType)) {
            $failures[] = "{$table}.{$column} type";
            echo "FAIL: {$table}.{$column} type '{$actualType}' lacks '{$containsType}'\n";
            return;
        }
    }

    echo "PASS: {$table}.{$column}" . ($nullable === null ? '' : ($nullable ? ' NULL' : ' NOT NULL')) . "\n";
}

function tableIndexes(string $table): array
{
    if (driver() === 'mysql') {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))->groupBy('Key_name')->keys()->all();
    }

    return collect(DB::select("PRAGMA index_list('{$table}')"))->pluck('name')->all();
}

function foreignKeys(string $table): array
{
    if (driver() === 'mysql') {
        return collect(DB::select(
            "SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, rc.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE kcu
             JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
               ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
              AND rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
             WHERE kcu.CONSTRAINT_SCHEMA = DATABASE() AND kcu.TABLE_NAME = ?
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL",
            [$table]
        ))->keyBy('REFERENCED_TABLE_NAME')->toArray();
    }

    // sqlite foreign_key_list gives id/seq rows per FK.
    return collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
        ->groupBy('table')
        ->map(fn ($rows) => (object) [
            'COLUMN_NAME' => $rows->first()->from,
            'REFERENCED_TABLE_NAME' => $rows->first()->table,
            'ON_DELETE' => strtoupper($rows->first()->on_delete),
        ])
        ->keyBy('REFERENCED_TABLE_NAME')
        ->toArray();
}

/* ------------------------------------------------------------------ */
/* Assertions                                                          */
/* ------------------------------------------------------------------ */

function assertFullSchema(): void
{
    foreach (
        ['digital_assets', 'digital_entitlements', 'digital_asset_entitlement',
         'digital_download_logs', 'digital_license_keys'] as $t
    ) {
        check("table exists: {$t}", Schema::hasTable($t));
    }

    // ---- digital_assets -------------------------------------------------
    foreach (['id', 'uuid', 'product_id', 'type', 'disk', 'path', 'original_name',
        'display_name', 'mime', 'extension', 'size', 'checksum', 'status',
        'metadata', 'external_url', 'secret', 'sort_order', 'expires_at',
        'created_at', 'updated_at'] as $c) {
        check("digital_assets has column {$c}", Schema::hasColumn('digital_assets', $c));
    }

    assertColumn('digital_assets', 'path', true);
    assertColumn('digital_assets', 'display_name', true);
    assertColumn('digital_assets', 'extension', true, 'var');
    assertColumn('digital_assets', 'checksum', true, 'char');
    assertColumn('digital_assets', 'status', false);
    assertColumn('digital_assets', 'metadata', true);
    assertColumn('digital_assets', 'external_url', true);
    assertColumn('digital_assets', 'secret', true);
    assertColumn('digital_assets', 'expires_at', true);
    assertColumn('digital_assets', 'original_name', false);
    assertColumn('digital_assets', 'mime', false);
    assertColumn('digital_assets', 'size', false);

    $assetIdx = tableIndexes('digital_assets');
    check('digital_assets uuid unique index', collect($assetIdx)->contains(fn ($i) => str_contains(strtolower($i), 'uuid')));
    check('digital_assets product+sort index', collect($assetIdx)->contains(fn ($i) => str_contains(strtolower($i), 'product_sort')));
    check('digital_assets product+status index', collect($assetIdx)->contains(fn ($i) => str_contains(strtolower($i), 'product_status')));

    $assetFks = foreignKeys('digital_assets');
    check(
        'digital_assets.product_id FK → products CASCADE',
        isset($assetFks['products'])
            && $assetFks['products']->COLUMN_NAME === 'product_id'
            && (driver() === 'mysql'
                ? strtoupper($assetFks['products']->DELETE_RULE) === 'CASCADE'
                : $assetFks['products']->ON_DELETE === 'CASCADE')
    );

    // ---- digital_license_keys -------------------------------------------
    foreach (['id', 'uuid', 'asset_id', 'encrypted_key', 'status',
        'allocated_entitlement_id', 'assigned_at', 'revealed_at',
        'consumed_at', 'revoked_at', 'created_at', 'updated_at'] as $c) {
        check("digital_license_keys has column {$c}", Schema::hasColumn('digital_license_keys', $c));
    }

    assertColumn('digital_license_keys', 'encrypted_key', false);
    assertColumn('digital_license_keys', 'allocated_entitlement_id', true);
    assertColumn('digital_license_keys', 'status', false);

    $keyIdx = tableIndexes('digital_license_keys');
    check('license_keys uuid unique index', collect($keyIdx)->contains(fn ($i) => str_contains(strtolower($i), 'uuid')));
    check('license_keys asset+status composite index', collect($keyIdx)->contains(fn ($i) => str_contains(strtolower($i), 'asset_status')));
    check('license_keys allocation index', collect($keyIdx)->contains(fn ($i) => str_contains(strtolower($i), 'allocation')));

    $keyFks = foreignKeys('digital_license_keys');
    check(
        'license_keys.asset_id FK → digital_assets CASCADE',
        isset($keyFks['digital_assets'])
            && (driver() === 'mysql'
                ? strtoupper($keyFks['digital_assets']->DELETE_RULE) === 'CASCADE'
                : $keyFks['digital_assets']->ON_DELETE === 'CASCADE')
    );
    check(
        'license_keys.allocated_entitlement_id FK SET NULL',
        isset($keyFks['digital_entitlements'])
            && (driver() === 'mysql'
                ? strtoupper($keyFks['digital_entitlements']->DELETE_RULE) === 'SET NULL'
                : $keyFks['digital_entitlements']->ON_DELETE === 'SET NULL')
    );

    // ---- digital_entitlements -------------------------------------------
    foreach (['id', 'uuid', 'order_id', 'order_product_id', 'user_id', 'status',
        'delivered_at', 'download_limit', 'download_count', 'revoked_at',
        'expires_at'] as $c) {
        check("digital_entitlements has column {$c}", Schema::hasColumn('digital_entitlements', $c));
    }
    assertColumn('digital_entitlements', 'expires_at', true);

    // ---- defaults --------------------------------------------------------
    $defaultCheck = DB::table('digital_assets')->insertGetId([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'product_id' => legacySeedProduct(),
        'original_name' => 'default-probe.pdf',
        'mime' => 'application/pdf',
        'size' => 10,
    ]);
    $row = DB::table('digital_assets')->where('id', $defaultCheck)->first();
    check('new asset row defaults status=active', $row->status === 'active');
    check('new asset row keeps FILE default type', $row->type === 'FILE');
    DB::table('digital_assets')->where('id', $defaultCheck)->delete();
}

/** Minimal product row satisfying the products FK (schema-level only). */
function legacySeedProduct(): int
{
    static $id = null;

    // migrate:fresh wipes tables between phases — never trust a stale id.
    if ($id !== null && DB::table('products')->where('id', $id)->exists()) {
        return $id;
    }

    $now = now();
    $data = [
        'name' => json_encode(['en' => 'W3 Legacy Product']),
        'slug' => 'w3-legacy-' . Illuminate\Support\Str::random(6),
        'description' => json_encode(['en' => 'legacy']),
        'price' => 10,
        'item_type' => 'DIGITAL',
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $columns = collect(columnMeta('products'))->keys();
    $data = array_intersect_key($data, array_flip($columns->all()));

    $id = (int) DB::table('products')->insertGetId($data);

    return $id;
}

/* ------------------------------------------------------------------ */
/* Modes                                                               */
/* ------------------------------------------------------------------ */

$mode = $argv[1] ?? 'fresh';

echo '== driver=' . driver() . ' db=' . (config('database.connections.' . driver())['database'] ?? '?') . " ==\n";

if ($mode === 'fresh') {
    Artisan::call('migrate:fresh', ['--force' => true]);
    echo Artisan::output();
    assertFullSchema();
}

if ($mode === 'lifecycle') {
    // A) clean slate, full W3 schema
    Artisan::call('migrate:fresh', ['--force' => true]);
    echo "== A) migrate:fresh complete ==\n";

    // B) roll back the three W3 migrations
    Artisan::call('migrate:rollback', ['--step' => 3, '--force' => true]);
    echo Artisan::output();
    global $failures, $total;
    check('rollback removes digital_license_keys', !Schema::hasTable('digital_license_keys'));
    check('rollback removes entitlements.expires_at', !Schema::hasColumn('digital_entitlements', 'expires_at'));
    $postRollbackCols = columnMeta('digital_assets');
    $preW3NewCols = ['display_name', 'extension', 'checksum', 'status', 'metadata', 'external_url', 'secret', 'expires_at'];
    foreach ($preW3NewCols as $c) {
        check("rollback removes digital_assets.{$c}", !isset($postRollbackCols[$c]));
    }
    $pathCol = driver() === 'mysql' ? $postRollbackCols['path']->Null : null;
    check(
        'rollback restores path NOT NULL semantics',
        driver() === 'sqlite' ? ((int) $postRollbackCols['path']->notnull) === 1 : strtoupper($pathCol) === 'NO'
    );

    // C) seed a legacy-style PDF asset on the OLD schema
    $productId = legacySeedProduct();
    $legacyId = DB::table('digital_assets')->insertGetId([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'product_id' => $productId,
        'type' => 'FILE',
        'disk' => 'private',
        'path' => "digital-assets/{$productId}/legacy.pdf",
        'original_name' => 'Legacy Manual',
        'mime' => 'application/pdf',
        'size' => 2048,
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    echo "== C) legacy-style row seeded id={$legacyId} ==\n";

    // D) re-apply the three W3 migrations
    Artisan::call('migrate', ['--force' => true]);
    echo Artisan::output();
    $migratedRow = DB::table('digital_assets')->where('id', $legacyId)->first();
    check('existing row survives W3 re-migration', $migratedRow !== null);
    check('existing row keeps original_name', $migratedRow && $migratedRow->original_name === 'Legacy Manual');
    check('existing row keeps mime', $migratedRow && $migratedRow->mime === 'application/pdf');
    check('existing row keeps size', $migratedRow && (int) $migratedRow->size === 2048);
    check('existing row keeps type=FILE', $migratedRow && $migratedRow->type === 'FILE');
    check('existing row keeps original path', $migratedRow && str_contains($migratedRow->path, 'legacy.pdf'));
    check('existing row backfills status=active', $migratedRow && $migratedRow->status === 'active');

    // E) roll W3 back AGAIN over existing data (limitation guard)
    Artisan::call('migrate:rollback', ['--step' => 3, '--force' => true]);
    echo Artisan::output();
    $survivor = DB::table('digital_assets')->where('id', $legacyId)->first();
    check('file-backed row survives second rollback', $survivor !== null && $survivor->original_name === 'Legacy Manual');

    // F) double fresh proof
    Artisan::call('migrate:fresh', ['--force' => true]);
    Artisan::call('migrate:fresh', ['--force' => true]);
    echo "== F) double migrate:fresh complete ==\n";
    assertFullSchema();
}

echo "\n==== RESULT: " . ($total - count($failures)) . "/{$total} checks passed ====\n";
exit(empty($failures) ? 0 : 1);

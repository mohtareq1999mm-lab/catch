<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1 hardening (additive, reversible):
     * - single-default backstop (partial unique: exactly one is_default=true).
     * - historical warehouse identity snapshot on fulfillments.
     */
    public function up(): void
    {
        // Normalize dirty data BEFORE the unique backstop: keep the lowest-id
        // active default (else lowest-id row), clear the rest. Without this a
        // legacy DB with 2+ defaults blocks the deploy.
        $this->dedupeDefaults();

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            // Stored generated flag: 1 when default, NULL otherwise.
            // UNIQUE allows many NULLs but only one 1 (exactly-one-default).
            if (!Schema::hasColumn('warehouses', 'default_singleton')) {
                DB::statement(
                    'ALTER TABLE `warehouses` ADD COLUMN `default_singleton` TINYINT(1) ' .
                    'GENERATED ALWAYS AS (IF(`is_default`, 1, NULL)) STORED, ' .
                    'ADD UNIQUE KEY `warehouses_single_default` (`default_singleton`)'
                );
            }
        } elseif ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS warehouses_single_default ' .
                'ON warehouses (is_default) WHERE is_default'
            );
        } else {
            // SQLite and others: integer comparison partial index.
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS warehouses_single_default ' .
                'ON warehouses (is_default) WHERE is_default = 1'
            );
        }

        Schema::table('fulfillments', function (Blueprint $table) {
            if (!Schema::hasColumn('fulfillments', 'warehouse_code')) {
                $table->string('warehouse_code', 50)->nullable()->after('warehouse_id');
            }
            if (!Schema::hasColumn('fulfillments', 'warehouse_name')) {
                $table->string('warehouse_name')->nullable()->after('warehouse_code');
            }
        });

        $this->backfillSnapshots($driver);
    }

    private function dedupeDefaults(): void
    {
        $ids = DB::table('warehouses')
            ->where('is_default', true)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (count($ids) <= 1) {
            return;
        }

        // Prefer an active row as the survivor, else the lowest id.
        $survivor = DB::table('warehouses')->whereIn('id', $ids)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->value('id') ?? $ids[0];

        DB::table('warehouses')->whereIn('id', $ids)
            ->where('id', '!=', $survivor)
            ->update(['is_default' => false]);

        Log::warning('Warehouses single-default dedupe', [
            'survivor' => $survivor, 'cleared' => count($ids) - 1,
        ]);
    }

    private function backfillSnapshots(string $driver): void
    {
        try {
            if ($driver === 'mysql') {
                DB::statement(
                    'UPDATE fulfillments f JOIN warehouses w ON w.id = f.warehouse_id ' .
                    'SET f.warehouse_code = w.code, f.warehouse_name = w.name ' .
                    'WHERE f.warehouse_code IS NULL'
                );

                return;
            }

            // Portable row-by-row fallback (sqlite/pgsql: no alias-SET).
            $rows = DB::table('fulfillments')
                ->whereNull('warehouse_code')
                ->select('id', 'warehouse_id')
                ->limit(5000)
                ->get();
            foreach ($rows as $row) {
                $wh = DB::table('warehouses')->where('id', $row->warehouse_id)
                    ->select('code', 'name')->first();
                if ($wh) {
                    DB::table('fulfillments')->where('id', $row->id)->update([
                        'warehouse_code' => $wh->code,
                        'warehouse_name' => $wh->name,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Backfill must never block deploy; creation path fills new rows.
            Log::warning('Fulfillment warehouse snapshot backfill skipped', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('fulfillments', function (Blueprint $table) {
            if (Schema::hasColumn('fulfillments', 'warehouse_code')) {
                $table->dropColumn('warehouse_code');
            }
            if (Schema::hasColumn('fulfillments', 'warehouse_name')) {
                $table->dropColumn('warehouse_name');
            }
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            if (Schema::hasColumn('warehouses', 'default_singleton')) {
                try {
                    DB::statement('ALTER TABLE `warehouses` DROP KEY `warehouses_single_default`');
                    DB::statement('ALTER TABLE `warehouses` DROP COLUMN `default_singleton`');
                } catch (\Throwable) {
                    // Already dropped.
                }
            }
        } else {
            DB::statement('DROP INDEX IF EXISTS warehouses_single_default');
        }
    }
};

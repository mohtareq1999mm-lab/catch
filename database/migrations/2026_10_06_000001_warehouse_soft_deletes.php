<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 — D-WH-DEL (Soft Delete):
     * - add warehouses.deleted_at (no physical deletes; history survives).
     * - re-scope the single-default backstop to NON-deleted rows only, so a
     *   soft-deleted ex-default can never remain the effective default and a
     *   replacement default can be promoted without a unique violation.
     * Additive + reversible; no FK changes (RESTRICT rows are retained).
     */
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            if (!Schema::hasColumn('warehouses', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            // The Phase-1 backstop always precedes this migration; guard
            // anyway so a partial state never blocks the deploy.
            if (Schema::hasColumn('warehouses', 'default_singleton')) {
                DB::statement('ALTER TABLE `warehouses` DROP KEY `warehouses_single_default`');
                DB::statement('ALTER TABLE `warehouses` DROP COLUMN `default_singleton`');
            }
            DB::statement(
                'ALTER TABLE `warehouses` ADD COLUMN `default_singleton` TINYINT(1) ' .
                'GENERATED ALWAYS AS (IF(`is_default` AND `deleted_at` IS NULL, 1, NULL)) STORED, ' .
                'ADD UNIQUE KEY `warehouses_single_default` (`default_singleton`)'
            );
        } else {
            DB::statement('DROP INDEX IF EXISTS warehouses_single_default');
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS warehouses_single_default ' .
                'ON warehouses (is_default) WHERE is_default = 1 AND deleted_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `warehouses` DROP KEY `warehouses_single_default`');
            DB::statement('ALTER TABLE `warehouses` DROP COLUMN `default_singleton`');
            DB::statement(
                'ALTER TABLE `warehouses` ADD COLUMN `default_singleton` TINYINT(1) ' .
                'GENERATED ALWAYS AS (IF(`is_default`, 1, NULL)) STORED, ' .
                'ADD UNIQUE KEY `warehouses_single_default` (`default_singleton`)'
            );
        } else {
            DB::statement('DROP INDEX IF EXISTS warehouses_single_default');
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS warehouses_single_default ' .
                'ON warehouses (is_default) WHERE is_default = 1'
            );
        }

        Schema::table('warehouses', function (Blueprint $table) {
            if (Schema::hasColumn('warehouses', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};

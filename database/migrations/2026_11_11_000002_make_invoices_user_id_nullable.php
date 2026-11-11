<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approved guest decision: invoices.user_id may be NULL for guest orders
 * (no fabricated customer accounts). transactions.user_id is already nullable
 * (2026_11_10_000001, approved B-1).
 *
 * Rollback risk (DATA-AFFECTING): restoring NOT NULL is impossible while any
 * guest invoice exists. down() refuses and reports instead of silently
 * deleting/rewriting financial rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (!Schema::hasTable('invoices') || !Schema::hasColumn('invoices', 'user_id')) {
            return;
        }

        if ($driver === 'mysql') {
            $column = DB::selectOne(
                "SELECT IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'user_id'"
            );

            if ($column && strtoupper((string) $column->IS_NULLABLE) === 'YES') {
                return;
            }

            // MySQL rejects changing an FK column without dropping the FK.
            $fk = DB::selectOne(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'
                   AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users'
                 LIMIT 1"
            );

            if ($fk) {
                DB::statement('ALTER TABLE `invoices` DROP FOREIGN KEY `' . $fk->CONSTRAINT_NAME . '`');
            }

            DB::statement('ALTER TABLE `invoices` MODIFY `user_id` BIGINT UNSIGNED NULL');

            DB::statement(
                'ALTER TABLE `invoices` ADD CONSTRAINT `invoices_user_id_foreign`
                 FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT'
            );

            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('invoices') || !Schema::hasColumn('invoices', 'user_id')) {
            return;
        }

        if (DB::table('invoices')->whereNull('user_id')->exists()) {
            throw new RuntimeException(
                'Cannot restore invoices.user_id NOT NULL: guest invoices with NULL user_id exist. ' .
                'This rollback is data-affecting and requires an explicit data decision.'
            );
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $fk = DB::selectOne(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'
                   AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users'
                 LIMIT 1"
            );

            if ($fk) {
                DB::statement('ALTER TABLE `invoices` DROP FOREIGN KEY `' . $fk->CONSTRAINT_NAME . '`');
            }

            DB::statement('ALTER TABLE `invoices` MODIFY `user_id` BIGINT UNSIGNED NOT NULL');
            DB::statement(
                'ALTER TABLE `invoices` ADD CONSTRAINT `invoices_user_id_foreign`
                 FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT'
            );

            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};

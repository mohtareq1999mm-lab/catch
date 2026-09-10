<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            try {
                $versionRow = DB::selectOne('SELECT VERSION() as version');
                $version = $versionRow->version ?? '0';
                if (version_compare($version, '8.0.16', '>=')) {
                    // Check if constraint already exists
                    $exists = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupon_assignment_usages' AND CONSTRAINT_NAME = 'chk_order_id_not_null'");
                    if (empty($exists)) {
                        DB::statement('ALTER TABLE coupon_assignment_usages ADD CONSTRAINT chk_order_id_not_null CHECK (order_id IS NOT NULL)');
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("CHECK constraint migration skipped: " . $e->getMessage());
            }
        } elseif ($driver === 'pgsql') {
            try {
                DB::statement('ALTER TABLE coupon_assignment_usages ADD CONSTRAINT chk_order_id_not_null CHECK (order_id IS NOT NULL)');
            } catch (\Throwable $e) {
                // Constraint may already exist
            }
        }
        // SQLite: NOT NULL in column definition is sufficient
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'pgsql'])) {
            try {
                if ($driver === 'mysql') {
                    DB::statement('ALTER TABLE coupon_assignment_usages DROP CHECK chk_order_id_not_null');
                } else {
                    DB::statement('ALTER TABLE coupon_assignment_usages DROP CONSTRAINT IF EXISTS chk_order_id_not_null');
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }
};

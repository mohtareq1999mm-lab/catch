<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("
                ALTER TABLE coupon_targetings
                MODIFY COLUMN mode ENUM('assignment', 'dynamic', 'assignment_and_dynamic', 'assignment_or_dynamic')
                NOT NULL DEFAULT 'dynamic'
            ");
        } elseif ($driver === 'sqlite') {
            // SQLite doesn't support ALTER COLUMN, so we recreate the table
            DB::statement("
                CREATE TABLE coupon_targetings_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    coupon_id INTEGER NOT NULL,
                    mode TEXT NOT NULL DEFAULT 'dynamic' CHECK(mode IN ('assignment', 'dynamic', 'assignment_and_dynamic', 'assignment_or_dynamic')),
                    require_claim INTEGER NOT NULL,
                    max_claims INTEGER,
                    rule_tree TEXT,
                    created_at TIMESTAMP,
                    updated_at TIMESTAMP,
                    claim_ttl_hours INTEGER,
                    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
                )
            ");

            DB::statement("
                INSERT INTO coupon_targetings_new
                SELECT id, coupon_id, mode, require_claim, max_claims, rule_tree, created_at, updated_at, claim_ttl_hours
                FROM coupon_targetings
            ");

            DB::statement("DROP TABLE coupon_targetings");
            DB::statement("ALTER TABLE coupon_targetings_new RENAME TO coupon_targetings");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // Check if any rows use new modes before downgrading
            $hasNewModes = DB::table('coupon_targetings')
                ->whereIn('mode', ['assignment_and_dynamic', 'assignment_or_dynamic'])
                ->exists();

            if ($hasNewModes) {
                throw new \RuntimeException(
                    'Cannot rollback: coupon_targetings table contains records with new mode values. ' .
                    'Delete or update these records before rolling back.'
                );
            }

            DB::statement("
                ALTER TABLE coupon_targetings
                MODIFY COLUMN mode ENUM('assignment', 'dynamic')
                NOT NULL DEFAULT 'dynamic'
            ");
        } elseif ($driver === 'sqlite') {
            DB::statement("
                CREATE TABLE coupon_targetings_old (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    coupon_id INTEGER NOT NULL,
                    mode TEXT NOT NULL DEFAULT 'dynamic' CHECK(mode IN ('assignment', 'dynamic')),
                    require_claim INTEGER NOT NULL,
                    max_claims INTEGER,
                    rule_tree TEXT,
                    created_at TIMESTAMP,
                    updated_at TIMESTAMP,
                    claim_ttl_hours INTEGER,
                    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
                )
            ");

            DB::statement("
                INSERT INTO coupon_targetings_old
                SELECT id, coupon_id, mode, require_claim, max_claims, rule_tree, created_at, updated_at, claim_ttl_hours
                FROM coupon_targetings
                WHERE mode IN ('assignment', 'dynamic')
            ");

            DB::statement("DROP TABLE coupon_targetings");
            DB::statement("ALTER TABLE coupon_targetings_old RENAME TO coupon_targetings");
        }
    }
};

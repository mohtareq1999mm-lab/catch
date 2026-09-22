<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repair coupon_targetings parity on SQLite.
     *
     * Root cause (P0 finding): 2026_09_14_000003 rebuilds coupon_targetings
     * manually on SQLite and drops three properties of the original Schema
     * definition (2026_09_10_000001):
     *   1. require_claim DEFAULT 0        -> recreated as NOT NULL without default
     *   2. UNIQUE(coupon_id)              -> dropped entirely
     *   3. INDEX(require_claim)           -> dropped entirely
     *
     * This broke EligibilityEngineTest (12 failures: inserts without
     * require_claim) and silently weakened the one-targeting-per-coupon
     * invariant in every SQLite environment.
     *
     * Production MySQL/PostgreSQL are unaffected (native ALTER preserves the
     * definition), so this migration is a strict no-op there.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        if (!Schema::hasTable('coupon_targetings')) {
            return;
        }

        // M6: the broken definition dropped UNIQUE(coupon_id), so a dirty
        // SQLite database may hold duplicates. Keep the earliest row per
        // coupon (first configuration wins) so the repaired UNIQUE cannot
        // abort the migration. SQLite-only repair path; production MySQL
        // never had the UNIQUE dropped and is untouched (early return above).
        DB::statement('
            DELETE FROM coupon_targetings
            WHERE id NOT IN (
                SELECT MIN(id) FROM coupon_targetings GROUP BY coupon_id
            )
        ');

        DB::statement('
            CREATE TABLE coupon_targetings_fixed (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                coupon_id INTEGER NOT NULL,
                mode TEXT NOT NULL DEFAULT \'dynamic\' CHECK(mode IN (\'assignment\', \'dynamic\', \'assignment_and_dynamic\', \'assignment_or_dynamic\')),
                require_claim INTEGER NOT NULL DEFAULT 0,
                max_claims INTEGER,
                rule_tree TEXT,
                created_at TIMESTAMP,
                updated_at TIMESTAMP,
                claim_ttl_hours INTEGER,
                FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
                UNIQUE (coupon_id)
            )
        ');

        DB::statement('
            INSERT INTO coupon_targetings_fixed
                (id, coupon_id, mode, require_claim, max_claims, rule_tree, created_at, updated_at, claim_ttl_hours)
            SELECT id, coupon_id, mode, require_claim, max_claims, rule_tree, created_at, updated_at, claim_ttl_hours
            FROM coupon_targetings
        ');

        DB::statement('DROP TABLE coupon_targetings');
        DB::statement('ALTER TABLE coupon_targetings_fixed RENAME TO coupon_targetings');
        DB::statement('CREATE INDEX IF NOT EXISTS coupon_targetings_require_claim_index ON coupon_targetings (require_claim)');
    }

    /**
     * Reverse the migration (schema-only; data preserved).
     */
    public function down(): void
    {
        // Intentionally not reversible to the broken definition.
    }
};

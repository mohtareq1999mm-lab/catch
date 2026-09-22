<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Repair the one-pending-order-per-user guard on engines where a later
     * table rebuild may have stripped the partial-index predicate.
     *
     * Root cause (P0 finding): 2026_08_31 created a PARTIAL unique index
     *   idx_orders_user_pending_unique ON orders(user_id) WHERE status='pending'.
     * Later orders migrations (e.g. 2026_09_09 tax simplification) call
     * dropColumn(), which on SQLite rebuilds the whole table via doctrine/dbal.
     * The rebuild recreates the unique index WITHOUT its WHERE predicate,
     * leaving a plain UNIQUE(user_id) that rejects ANY second order per user
     * (this broke AssignedCouponSystemTest multi-order scenarios).
     *
     * Production MySQL is unaffected (native ALTER preserves the generated
     * pending_user_id column + unique), so this migration is a strict no-op
     * there when the guard already exists.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // MySQL path uses the generated pending_user_id column; native
            // ALTER TABLE never drops it, so there is nothing to repair.
            // Fresh installs are covered by the original 2026_08_31 migration.
            return;
        }

        // SQLite / PostgreSQL: drop whatever exists under this name (the
        // predicate-stripped plain unique or a stale partial) and recreate
        // the true partial index.
        try {
            DB::statement('DROP INDEX IF EXISTS idx_orders_user_pending_unique');
        } catch (\Throwable $e) {
            // ignore if engine lacks IF EXISTS support variation
        }

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_user_pending_unique
            ON orders(user_id)
            WHERE status = 'pending'
        ");
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            return;
        }

        try {
            DB::statement('DROP INDEX IF EXISTS idx_orders_user_pending_unique');
        } catch (\Throwable $e) {
            // ignore
        }
    }
};

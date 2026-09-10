<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the `processing` order status to the orders.status enum.
 *
 * MySQL: native ALTER ... MODIFY.
 * SQLite: enums are VARCHAR + CHECK constraints and SQLite cannot ALTER a
 * CHECK. The table is rebuilt using the standard 12-step recipe (create
 * widened copy -> copy rows -> drop old -> rename) so the test suite's
 * sqlite schema matches production semantics exactly.
 */
return new class extends Migration
{
    private const WIDENED = "('pending', 'processing', 'completed', 'delivered', 'cancelled')";

    private const NARROWED = "('pending', 'completed', 'delivered', 'cancelled')";

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending', 'processing', 'completed', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending'");

            return;
        }

        if ($driver === 'sqlite') {
            $this->rebuildSqliteOrdersTable(self::NARROWED, self::WIDENED);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending', 'completed', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending'");

            return;
        }

        if ($driver === 'sqlite') {
            $this->rebuildSqliteOrdersTable(self::WIDENED, self::NARROWED);
        }
    }

    /**
     * Rebuild the orders table replacing the old status CHECK list with the
     * new one, preserving all rows, columns, defaults and inline UNIQUE
     * indexes (they live in the CREATE TABLE sql).
     */
    private function rebuildSqliteOrdersTable(string $fromList, string $toList): void
    {
        $tableSql = DB::select(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'orders'"
        );

        if (empty($tableSql) || !str_contains($tableSql[0]->sql ?? '', $fromList)) {
            return; // Nothing to widen/narrow (unexpected shape): leave as-is.
        }

        Schema::disableForeignKeyConstraints();

        try {
            $newSql = str_replace($fromList, $toList, $tableSql[0]->sql);
            $newSql = str_replace('CREATE TABLE "orders"', 'CREATE TABLE "orders_processing_new"', $newSql);
            $newSql = str_replace('CREATE TABLE orders', 'CREATE TABLE "orders_processing_new"', $newSql);

            // Separately-created indexes must be replayed after the swap.
            $indexSqls = collect(DB::select(
                "SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'orders' AND sql IS NOT NULL"
            ))->pluck('sql')->all();

            DB::statement($newSql);

            $columns = implode(', ', array_map(
                fn (string $c) => '"' . str_replace('"', '""', $c) . '"',
                Schema::getColumnListing('orders')
            ));

            DB::statement("INSERT INTO \"orders_processing_new\" ({$columns}) SELECT {$columns} FROM \"orders\"");
            DB::statement('DROP TABLE "orders"');
            DB::statement('ALTER TABLE "orders_processing_new" RENAME TO "orders"');

            foreach ($indexSqls as $sql) {
                DB::statement(str_replace('IF NOT EXISTS', '', $sql));
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order -> Flow assignment columns + widen orders.status for catalog codes.
 *
 * - Adds shipping_type (default local), flow_id, current_status_id.
 * - Backfills existing orders: local flow + catalog status matching legacy value.
 * - Widens orders.status ENUM to the full catalog code set (MySQL native
 *   MODIFY; SQLite table rebuild following the project recipe) so
 *   orders.status can mirror current_status_id for backward compatibility.
 *
 * Rollback limitation: narrowing the ENUM fails closed when rows use
 * logistics codes (a RuntimeException is thrown instead of losing data).
 */
return new class extends Migration
{
    private const LEGACY = "('pending', 'processing', 'completed', 'delivered', 'cancelled')";

    private const WIDENED = "('pending', 'processing', 'packed', 'shipped', 'in_transit', 'arrived_at_destination_country', 'customs_clearance', 'customs_cleared', 'local_carrier', 'out_for_delivery', 'delivered', 'failed_delivery', 'returned', 'completed', 'cancelled')";

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'shipping_type')) {
                $table->string('shipping_type', 30)->default('local')->after('shipping_method');
            }
            if (!Schema::hasColumn('orders', 'flow_id')) {
                $table->foreignId('flow_id')->nullable()->after('shipping_type')->constrained('order_flows')->restrictOnDelete();
            }
            if (!Schema::hasColumn('orders', 'current_status_id')) {
                $table->foreignId('current_status_id')->nullable()->after('flow_id')->constrained('order_statuses')->restrictOnDelete();
            }
        });

        if (Schema::hasColumn('orders', 'shipping_type')) {
            DB::table('orders')->whereNull('shipping_type')->update(['shipping_type' => 'local']);
        }

        $this->backfillFlows();
        $this->widenStatusEnum(self::LEGACY, self::WIDENED);

        if (Schema::hasColumn('orders', 'shipping_type')) {
            try {
                Schema::table('orders', function (Blueprint $table) {
                    $table->index('shipping_type', 'orders_shipping_type_idx');
                });
            } catch (\Throwable $e) {
                // Index already exists (partial re-run): never fail the deploy.
                logger()->warning('Order flow migration: shipping_type index skipped.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $extendedInUse = DB::table('orders')
            ->whereNotIn('status', ['pending', 'processing', 'completed', 'delivered', 'cancelled'])
            ->exists();

        if ($extendedInUse) {
            throw new \RuntimeException(
                'Cannot narrow orders.status: rows use logistics flow codes. Migrate them to legacy values first.'
            );
        }

        $this->widenStatusEnum(self::WIDENED, self::LEGACY);

        Schema::table('orders', function (Blueprint $table) {
            try {
                $table->dropIndex('orders_shipping_type_idx');
            } catch (\Throwable) {
            }
            try {
                $table->dropConstrainedForeignId('flow_id');
            } catch (\Throwable) {
                try {
                    $table->dropColumn('flow_id');
                } catch (\Throwable) {
                }
            }
            try {
                $table->dropConstrainedForeignId('current_status_id');
            } catch (\Throwable) {
                try {
                    $table->dropColumn('current_status_id');
                } catch (\Throwable) {
                }
            }
            try {
                $table->dropColumn('shipping_type');
            } catch (\Throwable) {
            }
        });
    }

    private function backfillFlows(): void
    {
        if (!Schema::hasTable('order_flows') || !Schema::hasTable('order_statuses')) {
            return;
        }

        // Fail-closed parity with runtime resolution: only an ACTIVE local
        // flow may adopt legacy orders.
        $localFlowId = DB::table('order_flows')
            ->where('shipping_type', 'local')
            ->where('is_active', true)
            ->value('id');
        if (!$localFlowId) {
            return;
        }

        $statusIds = DB::table('order_statuses')->pluck('id', 'code');

        if (Schema::hasColumn('orders', 'flow_id')) {
            DB::table('orders')->whereNull('flow_id')->update(['flow_id' => $localFlowId]);
        }

        // Every legacy status has a matching catalog row by design. Unknown
        // values cannot satisfy the FK: count them loudly and leave the
        // mirror null for manual remediation instead of failing the deploy.
        if (Schema::hasColumn('orders', 'current_status_id')) {
            foreach ($statusIds as $code => $id) {
                DB::table('orders')
                    ->where('status', $code)
                    ->whereNull('current_status_id')
                    ->update(['current_status_id' => $id]);
            }

            $unmapped = DB::table('orders')->whereNull('current_status_id')->count();
            if ($unmapped > 0) {
                logger()->warning('Order flow backfill: orders with unmapped status left without current_status_id.', [
                    'count' => $unmapped,
                ]);
            }
        }
    }

    private function widenStatusEnum(string $fromList, string $toList): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM{$toList} NOT NULL DEFAULT 'pending'");

            return;
        }

        if ($driver === 'sqlite') {
            $this->rebuildSqliteOrdersTable($fromList, $toList);
        }
    }

    /**
     * SQLite recipe (mirrors 2026_08_19 migration): rebuild the orders table
     * replacing the status CHECK list, preserving rows, columns, defaults and
     * separately-created indexes.
     */
    private function rebuildSqliteOrdersTable(string $fromList, string $toList): void
    {
        $tableSql = DB::select(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'orders'"
        );

        if (empty($tableSql) || !str_contains($tableSql[0]->sql ?? '', $fromList)) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        try {
            $newSql = str_replace($fromList, $toList, $tableSql[0]->sql);
            $newSql = str_replace('CREATE TABLE "orders"', 'CREATE TABLE "orders_flow_new"', $newSql);
            $newSql = str_replace('CREATE TABLE orders', 'CREATE TABLE "orders_flow_new"', $newSql);

            $indexSqls = collect(DB::select(
                "SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'orders' AND sql IS NOT NULL"
            ))->pluck('sql')->all();

            DB::statement($newSql);

            $columns = implode(', ', array_map(
                fn (string $c) => '"' . str_replace('"', '""', $c) . '"',
                Schema::getColumnListing('orders')
            ));

            DB::statement("INSERT INTO \"orders_flow_new\" ({$columns}) SELECT {$columns} FROM \"orders\"");
            DB::statement('DROP TABLE "orders"');
            DB::statement('ALTER TABLE "orders_flow_new" RENAME TO "orders"');

            foreach ($indexSqls as $sql) {
                DB::statement(str_replace('IF NOT EXISTS', '', $sql));
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
};

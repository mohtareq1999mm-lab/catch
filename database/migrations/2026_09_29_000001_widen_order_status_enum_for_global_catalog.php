<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen orders.status ENUM for the global Order Status catalog.
 *
 * Adds only ORDER-compatible vocabulary (custom-flows-only steps that carry
 * no payment/inventory/fulfillment side effects): confirmed, ready_to_ship,
 * ready_for_pickup, picked_up, export_processing, import_processing,
 * customs_hold.
 *
 * Payment / refund / return / shipment-owned states are DELIBERATELY absent:
 * they belong to their own subsystems (orders.payment_status, refund policy
 * keeps status completed, return_requests tables, shipments table).
 *
 * Does NOT modify any existing migration. MySQL: native MODIFY. SQLite:
 * table rebuild following the project recipe.
 */
return new class extends Migration
{
    private const FROM = "('pending', 'processing', 'packed', 'shipped', 'in_transit', 'arrived_at_destination_country', 'customs_clearance', 'customs_cleared', 'local_carrier', 'out_for_delivery', 'delivered', 'failed_delivery', 'returned', 'completed', 'cancelled')";

    private const TO = "('pending', 'processing', 'packed', 'shipped', 'in_transit', 'arrived_at_destination_country', 'customs_clearance', 'customs_hold', 'customs_cleared', 'local_carrier', 'out_for_delivery', 'delivered', 'failed_delivery', 'returned', 'completed', 'cancelled', 'confirmed', 'ready_to_ship', 'ready_for_pickup', 'picked_up', 'export_processing', 'import_processing')";

    public function up(): void
    {
        $this->convert(self::FROM, self::TO);
    }

    public function down(): void
    {
        $inUse = DB::table('orders')
            ->whereNotIn('status', ['pending', 'processing', 'packed', 'shipped', 'in_transit', 'arrived_at_destination_country', 'customs_clearance', 'customs_cleared', 'local_carrier', 'out_for_delivery', 'delivered', 'failed_delivery', 'returned', 'completed', 'cancelled'])
            ->exists();

        if ($inUse) {
            throw new \RuntimeException(
                'Cannot narrow orders.status: rows use global catalog codes. Migrate them first.'
            );
        }

        $this->convert(self::TO, self::FROM);
    }

    private function convert(string $fromList, string $toList): void
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
            $newSql = str_replace('CREATE TABLE "orders"', 'CREATE TABLE "orders_catalog_new"', $newSql);
            $newSql = str_replace('CREATE TABLE orders', 'CREATE TABLE "orders_catalog_new"', $newSql);

            $indexSqls = collect(DB::select(
                "SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'orders' AND sql IS NOT NULL"
            ))->pluck('sql')->all();

            DB::statement($newSql);

            $columns = implode(', ', array_map(
                fn (string $c) => '"' . str_replace('"', '""', $c) . '"',
                Schema::getColumnListing('orders')
            ));

            DB::statement("INSERT INTO \"orders_catalog_new\" ({$columns}) SELECT {$columns} FROM \"orders\"");
            DB::statement('DROP TABLE "orders"');
            DB::statement('ALTER TABLE "orders_catalog_new" RENAME TO "orders"');

            foreach ($indexSqls as $sql) {
                DB::statement(str_replace('IF NOT EXISTS', '', $sql));
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
};

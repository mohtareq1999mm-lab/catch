<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7 (D7-4) — make RESTORED a real inventory state.
     * InventoryRestoreService claims COMMITTED → RESTORED as the single
     * exactly-once restoration authority, but the orders.inventory_state
     * enum never contained 'restored' (non-strict MySQL persisted it as
     * ''). This adds the value so the canonical claim is explicit,
     * queryable and portable. Additive + reversible; no data rewrite on
     * the way up ('' rows from older restores keep working: '' is still
     * not COMMITTED, so the claim stays won exactly once).
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE `orders` MODIFY `inventory_state` " .
            "ENUM('none','active','released','committed','restored') " .
            "NOT NULL DEFAULT 'none'"
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        // Lossy by nature: restored rows return to committed (the state
        // they held before restoration) so the shrink never orphans data.
        DB::statement("UPDATE `orders` SET `inventory_state` = 'committed' WHERE `inventory_state` = 'restored'");
        DB::statement(
            "ALTER TABLE `orders` MODIFY `inventory_state` " .
            "ENUM('none','active','released','committed') " .
            "NOT NULL DEFAULT 'none'"
        );
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8 (D8-1, D8-10) — shipment cardinality backstop + cancellation audit.
     *
     * D8-1: one ACTIVE shipment per fulfillment. "Active" = fulfillment-linked
     * AND status NOT IN (cancelled, delivered, returned). A VIRTUAL generated
     * column maps every non-active row to NULL (NULLs never conflict in a
     * MySQL UNIQUE index), so the UNIQUE backstop fires only on a second
     * concurrent active row for the same fulfillment. VIRTUAL (not STORED):
     * probed on MySQL 8.4.3 — STORED generated columns are rejected with
     * error 1215 in this environment while VIRTUAL + UNIQUE enforces
     * identically. The application invariant (fulfillment lock + fresh
     * active-row check in ShipmentService::createForFulfillment) stays
     * primary and loud; this index is the race backstop. Historical rows
     * (cancelled / delivered / returned) are retained, never deleted.
     *
     * D8-10: cancellation audit following the Phase-7 fulfillment convention
     * (nullable FK actor, explicit source, never fabricated) plus
     * cancelled_at / cancel_reason.
     *
     * Additive + reversible; existing rows stay valid (all nullable, the
     * generated column computes automatically). If production ever held two
     * active rows for one fulfillment, this migration fails loudly instead
     * of silently blessing the violation.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('shipments', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('shipments', 'cancel_source')) {
                $table->string('cancel_source', 32)->nullable();
            }
            if (!Schema::hasColumn('shipments', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
            if (!Schema::hasColumn('shipments', 'cancel_reason')) {
                $table->text('cancel_reason')->nullable();
            }
        });

        if (Schema::getConnection()->getDriverName() === 'mysql'
            && !self::activeBackstopExists()
        ) {
            DB::statement(
                "ALTER TABLE `shipments` ADD COLUMN `active_fulfillment_id` BIGINT UNSIGNED " .
                "GENERATED ALWAYS AS (CASE WHEN `fulfillment_id` IS NULL " .
                "OR `status` IN ('cancelled', 'delivered', 'returned') " .
                "THEN NULL ELSE `fulfillment_id` END) VIRTUAL"
            );
            DB::statement(
                "CREATE UNIQUE INDEX `shipments_active_fulfillment_unique` " .
                "ON `shipments` (`active_fulfillment_id`)"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql'
            && self::activeBackstopExists()
        ) {
            DB::statement("DROP INDEX `shipments_active_fulfillment_unique` ON `shipments`");
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropColumn('active_fulfillment_id');
            });
        }

        Schema::table('shipments', function (Blueprint $table) {
            if (Schema::hasColumn('shipments', 'cancel_reason')) {
                $table->dropColumn('cancel_reason');
            }
            if (Schema::hasColumn('shipments', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
            if (Schema::hasColumn('shipments', 'cancel_source')) {
                $table->dropColumn('cancel_source');
            }
            if (Schema::hasColumn('shipments', 'cancelled_by')) {
                $table->dropConstrainedForeignId('cancelled_by');
            }
        });
    }

    private static function activeBackstopExists(): bool
    {
        return (bool) DB::selectOne(
            "SELECT 1 FROM information_schema.statistics " .
            "WHERE table_schema = DATABASE() AND table_name = 'shipments' " .
            "AND index_name = 'shipments_active_fulfillment_unique'"
        );
    }
};

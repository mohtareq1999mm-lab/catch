<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7 (D7-2) — fulfillment cancellation actor audit.
     * Adds explicit nullable queryable columns so operations can answer
     * who cancelled a fulfillment, when (cancelled_at, existing), why
     * (notes reason, existing) and from which source — without abusing
     * the metadata JSON. Follows the assigned_to actor convention
     * (nullable FK to users, SET NULL on delete). Additive + reversible;
     * existing rows stay valid (all nullable, no backfill).
     */
    public function up(): void
    {
        Schema::table('fulfillments', function (Blueprint $table) {
            if (!Schema::hasColumn('fulfillments', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('fulfillments', 'cancel_source')) {
                $table->string('cancel_source', 32)->nullable();
            }
        });

        Schema::table('fulfillments', function (Blueprint $table) {
            if (!self::cancelSourceIndexExists()) {
                $table->index('cancel_source', 'fulfillments_cancel_source_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fulfillments', function (Blueprint $table) {
            if (self::cancelSourceIndexExists()) {
                $table->dropIndex('fulfillments_cancel_source_index');
            }
        });

        Schema::table('fulfillments', function (Blueprint $table) {
            if (Schema::hasColumn('fulfillments', 'cancel_source')) {
                $table->dropColumn('cancel_source');
            }
            if (Schema::hasColumn('fulfillments', 'cancelled_by')) {
                $table->dropConstrainedForeignId('cancelled_by');
            }
        });
    }

    /**
     * Laravel 10 has no Schema::hasIndex — probe information_schema on
     * MySQL (the canonical project database); non-MySQL assumes absent
     * on the way up so the index is still created.
     */
    private static function cancelSourceIndexExists(): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return false;
        }

        return (bool) \Illuminate\Support\Facades\DB::selectOne(
            "SELECT 1 FROM information_schema.statistics " .
            "WHERE table_schema = DATABASE() AND table_name = 'fulfillments' " .
            "AND index_name = 'fulfillments_cancel_source_index'"
        );
    }
};

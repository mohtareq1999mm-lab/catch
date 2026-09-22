<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AREA_IN saved-address remediation: link saved addresses to the
     * canonical governorates table so coupon area eligibility can be
     * evaluated from the authenticated user's own addresses.
     *
     * Nullable + no backfill by design: pre-existing rows keep NULL and
     * fail closed for area_in until legitimately updated. No address JSON
     * parsing, no heuristic inference.
     */
    public function up(): void
    {
        if (!Schema::hasTable('address')) {
            return;
        }

        if (!Schema::hasColumn('address', 'governorate_id')) {
            Schema::table('address', function (Blueprint $table) {
                $table->unsignedBigInteger('governorate_id')->nullable()->after('customer_id');
                $table->foreign('governorate_id')->references('id')->on('governorates')->nullOnDelete();
                // Access pattern: customer scope + allowed-list match.
                $table->index(['customer_id', 'governorate_id']);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('address')) {
            return;
        }

        Schema::table('address', function (Blueprint $table) {
            try {
                $table->dropForeign(['governorate_id']);
            } catch (\Throwable $e) {
                // SQLite names FKs implicitly; dropping the column suffices.
            }
            try {
                $table->dropIndex(['customer_id', 'governorate_id']);
            } catch (\Throwable $e) {
            }
            if (Schema::hasColumn('address', 'governorate_id')) {
                $table->dropColumn('governorate_id');
            }
        });
    }
};

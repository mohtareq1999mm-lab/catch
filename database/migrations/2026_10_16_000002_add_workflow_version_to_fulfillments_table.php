<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Q-04: pin workflow version to fulfillment at creation.
     * Nullable = safe legacy behavior when no version published yet.
     * Existing rows keep NULL (legacy) and are never backfilled.
     * SAFE: additive nullable column + index, fully reversible.
     */
    public function up(): void
    {
        Schema::table('fulfillments', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_workflow_version_id')->nullable()->after('warehouse_id');
            $table->index('warehouse_workflow_version_id', 'fulfillments_workflow_version_idx');
        });

        // FK added separately so rollback order stays safe on MySQL.
        Schema::table('fulfillments', function (Blueprint $table) {
            $table->foreign('warehouse_workflow_version_id', 'fulfillments_workflow_version_fk')
                ->references('id')
                ->on('warehouse_workflow_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fulfillments', function (Blueprint $table) {
            try {
                $table->dropForeign('fulfillments_workflow_version_fk');
            } catch (Throwable $e) {
                // Fresh DBs without the FK still roll back cleanly.
            }
        });

        Schema::table('fulfillments', function (Blueprint $table) {
            if (Schema::hasColumn('fulfillments', 'warehouse_workflow_version_id')) {
                $table->dropIndex('fulfillments_workflow_version_idx');
                $table->dropColumn('warehouse_workflow_version_id');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7: warehouse/location hardening (additive, reversible).
     * - locations.barcode: unique nullable scan identity (WHERE).
     * - product_locations.reserved_quantity → allocated_hint: kills the
     *   second-authority confusion; hints never decide sellability.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('barcode', 100)->nullable()->unique()->after('code');
        });

        Schema::table('product_locations', function (Blueprint $table) {
            $table->renameColumn('reserved_quantity', 'allocated_hint');
        });
    }

    public function down(): void
    {
        Schema::table('product_locations', function (Blueprint $table) {
            $table->renameColumn('allocated_hint', 'reserved_quantity');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
            $table->dropColumn('barcode');
        });
    }
};

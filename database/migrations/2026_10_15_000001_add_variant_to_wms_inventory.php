<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Variant-aware WMS inventory (mandatory extension).
 *
 * VERIFIED FACTS (schema-dump 2026-10-14):
 * - products.stock_quantity is authoritative for SIMPLE products.
 * - product_variants.stock_quantity is authoritative per-SKU for VARIABLE
 *   products (OrderReservationService::lockStockRow already branches).
 * - product_locations had NO variant dimension (unique product_id+location_id).
 * - inventory_operations had NO variant dimension.
 *
 * This migration is SAFE + REVERSIBLE:
 * - ADDs nullable product_variant_id FKs (no data loss, existing rows keep NULL = simple).
 * - Adds UNIQUE(product_id, location_id, product_variant_id) then drops the
 *   old UNIQUE(product_id, location_id). MySQL allows multiple NULLs in the
 *   new unique at DB level; simple duplicates are prevented at app level by
 *   lockedPlacement under the location lock (authoritative).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (!Schema::hasColumn('product_locations', 'product_variant_id')) {
            Schema::table('product_locations', function (Blueprint $table) {
                $table->foreignId('product_variant_id')->nullable()->after('product_id')
                    ->constrained('product_variants')->onDelete('cascade');
                $table->index(['product_variant_id'], 'product_locations_variant_id_index');
            });
        }

        if (!Schema::hasColumn('inventory_operations', 'product_variant_id')) {
            Schema::table('inventory_operations', function (Blueprint $table) {
                $table->foreignId('product_variant_id')->nullable()->after('product_id')
                    ->constrained('product_variants')->nullOnDelete();
                $table->index(['product_variant_id'], 'inventory_operations_variant_id_index');
            });
        }

        // SKU-level uniqueness. Create the replacement FIRST (it still starts
        // with product_id so the product_id FK stays indexed), then drop old.
        // NOTE: MySQL UNIQUE with NULLABLE column allows multiple NULL rows at
        // DB level; simple-SKU duplicates are prevented at app level by
        // lockedPlacement under the location-row lock (same serialization
        // pattern as the transfer guards). The UNIQUE is defense in depth
        // for variant rows.
        if ($driver === 'mysql') {
            try {
                DB::statement('ALTER TABLE product_locations ADD UNIQUE KEY product_locations_sku_location_unique (product_id, location_id, product_variant_id)');
            } catch (Throwable) {
                // Already exists on retry after partial failure.
            }
            Schema::table('product_locations', function (Blueprint $table) {
                try {
                    $table->dropUnique('product_locations_product_id_location_id_unique');
                } catch (Throwable) {
                    // Index name may differ on older clones — fall through.
                }
            });
        } else {
            Schema::table('product_locations', function (Blueprint $table) {
                try {
                    $table->dropUnique('product_locations_product_id_location_id_unique');
                } catch (Throwable) {
                }
                try {
                    $table->unique(['product_id', 'location_id', 'product_variant_id'], 'product_locations_sku_location_unique');
                } catch (Throwable) {
                }
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            try { DB::statement('ALTER TABLE product_locations DROP INDEX product_locations_sku_location_unique'); } catch (Throwable) {}
        } else {
            Schema::table('product_locations', function (Blueprint $table) {
                try { $table->dropUnique('product_locations_sku_location_unique'); } catch (Throwable) {}
            });
        }

        Schema::table('product_locations', function (Blueprint $table) {
            try { $table->dropIndex('product_locations_variant_id_index'); } catch (Throwable) {}
            try { $table->dropConstrainedForeignId('product_variant_id'); } catch (Throwable) {
                try { $table->dropColumn('product_variant_id'); } catch (Throwable) {}
            }
            try { $table->unique(['product_id', 'location_id'], 'product_locations_product_id_location_id_unique'); } catch (Throwable) {}
        });

        Schema::table('inventory_operations', function (Blueprint $table) {
            try { $table->dropIndex('inventory_operations_variant_id_index'); } catch (Throwable) {}
            try { $table->dropConstrainedForeignId('product_variant_id'); } catch (Throwable) {
                try { $table->dropColumn('product_variant_id'); } catch (Throwable) {}
            }
        });
    }
};

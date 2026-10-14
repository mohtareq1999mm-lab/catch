<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
            } catch (Throwable $e) {
                // Already exists on retry after partial failure.
                Log::warning('WMS variant migration: SKU UNIQUE add skipped', ['error' => $e->getMessage()]);
            }
            // NOTE: a separate raw statement (not $table->dropUnique inside
            // the closure below): Blueprint commands execute when the
            // closure returns, so closure-level try/catch cannot catch
            // execution errors. Each statement carries its own catch.
            try {
                DB::statement('ALTER TABLE product_locations DROP INDEX product_locations_product_id_location_id_unique');
            } catch (Throwable $e) {
                // Already dropped, or a legacy clone uses a different name.
                Log::warning('WMS variant migration: pair UNIQUE drop skipped', ['error' => $e->getMessage()]);
            }
        } else {
            // One Schema::table call per statement so the surrounding
            // try/catch is effective (same Blueprint-execution reason).
            // Add-first, mirroring MySQL: the replacement exists before
            // the old key is removed.
            try {
                Schema::table('product_locations', function (Blueprint $table) {
                    $table->unique(['product_id', 'location_id', 'product_variant_id'], 'product_locations_sku_location_unique');
                });
            } catch (Throwable $e) {
                Log::warning('WMS variant migration: SKU UNIQUE add skipped', ['error' => $e->getMessage()]);
            }
            try {
                Schema::table('product_locations', function (Blueprint $table) {
                    $table->dropUnique('product_locations_product_id_location_id_unique');
                });
            } catch (Throwable $e) {
                Log::warning('WMS variant migration: pair UNIQUE drop skipped', ['error' => $e->getMessage()]);
            }
        }
    }

    public function down(): void
    {
        // NOTE on structure: Blueprint commands ($table->...) execute when
        // their Schema::table closure returns, so try/catch INSIDE such a
        // closure cannot catch execution errors (proven: MySQL 1553 on
        // dropIndex bubbled out). Every fallible statement below therefore
        // runs as its own raw statement (MySQL) or its own Schema::table
        // call (other drivers), each with an effective surrounding catch.
        // Order matters on MySQL: the product_id FK requires an index
        // starting with product_id, and the variant FK pins its own index —
        // so restore the pair UNIQUE first, then drop FKs, then indexes.
        //
        // Fail-closed pre-flight: rolling back while variant-scoped
        // placement rows exist would permanently destroy their SKU identity
        // (the restored pair UNIQUE cannot represent two variants sharing
        // one bin), and duplicate simple rows would make the pair UNIQUE
        // unrestorable. Refuse loudly instead of silently degrading —
        // reallocate/remove those placements first, then retry the rollback.
        if (Schema::hasColumn('product_locations', 'product_variant_id')) {
            $variantRows = (int) DB::table('product_locations')->whereNotNull('product_variant_id')->count();
            if ($variantRows > 0) {
                throw new \RuntimeException(
                    "Refusing WMS variant rollback: {$variantRows} variant-scoped placement row(s) exist; their SKU identity would be destroyed."
                );
            }
            $duplicateSimple = DB::table('product_locations')
                ->select('product_id', 'location_id')
                ->whereNull('product_variant_id')
                ->groupBy('product_id', 'location_id')
                ->havingRaw('COUNT(*) > 1')
                ->exists();
            if ($duplicateSimple) {
                throw new \RuntimeException(
                    'Refusing WMS variant rollback: duplicate simple placement rows exist; the pair UNIQUE cannot be restored.'
                );
            }
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            try { DB::statement('ALTER TABLE product_locations ADD UNIQUE KEY product_locations_product_id_location_id_unique (product_id, location_id)'); } catch (Throwable $e) { Log::warning('WMS variant rollback: pair UNIQUE restore skipped', ['error' => $e->getMessage()]); }
            try { DB::statement('ALTER TABLE product_locations DROP FOREIGN KEY product_locations_product_variant_id_foreign'); } catch (Throwable $e) { Log::warning('WMS variant rollback: variant FK drop skipped', ['error' => $e->getMessage()]); }
            try { DB::statement('ALTER TABLE product_locations DROP INDEX product_locations_sku_location_unique'); } catch (Throwable $e) { Log::warning('WMS variant rollback: SKU UNIQUE drop skipped', ['error' => $e->getMessage()]); }
            try { DB::statement('ALTER TABLE product_locations DROP INDEX product_locations_variant_id_index'); } catch (Throwable $e) { Log::warning('WMS variant rollback: variant index drop skipped', ['error' => $e->getMessage()]); }
            try { DB::statement('ALTER TABLE product_locations DROP COLUMN product_variant_id'); } catch (Throwable $e) { Log::warning('WMS variant rollback: variant column drop skipped', ['error' => $e->getMessage()]); }
            try { DB::statement('ALTER TABLE inventory_operations DROP FOREIGN KEY inventory_operations_product_variant_id_foreign'); } catch (Throwable $e) { Log::warning('WMS variant rollback: operations variant FK drop skipped', ['error' => $e->getMessage()]); }
            try { DB::statement('ALTER TABLE inventory_operations DROP INDEX inventory_operations_variant_id_index'); } catch (Throwable $e) { Log::warning('WMS variant rollback: operations variant index drop skipped', ['error' => $e->getMessage()]); }
            try { DB::statement('ALTER TABLE inventory_operations DROP COLUMN product_variant_id'); } catch (Throwable $e) { Log::warning('WMS variant rollback: operations variant column drop skipped', ['error' => $e->getMessage()]); }
        } else {
            // Same order as MySQL: restore pair UNIQUE → drop FKs → drop
            // indexes → drop columns.
            try {
                Schema::table('product_locations', function (Blueprint $table) {
                    $table->unique(['product_id', 'location_id'], 'product_locations_product_id_location_id_unique');
                });
            } catch (Throwable $e) {
                Log::warning('WMS variant rollback: pair UNIQUE restore skipped', ['error' => $e->getMessage()]);
            }
            try {
                Schema::table('product_locations', function (Blueprint $table) {
                    $table->dropUnique('product_locations_sku_location_unique');
                });
            } catch (Throwable $e) {
                Log::warning('WMS variant rollback: SKU UNIQUE drop skipped', ['error' => $e->getMessage()]);
            }
            try {
                Schema::table('product_locations', function (Blueprint $table) {
                    $table->dropForeign(['product_variant_id']);
                });
            } catch (Throwable $e) {
                Log::warning('WMS variant rollback: variant FK drop skipped', ['error' => $e->getMessage()]);
            }
            try {
                Schema::table('product_locations', function (Blueprint $table) {
                    $table->dropIndex('product_locations_variant_id_index');
                });
            } catch (Throwable $e) {
                Log::warning('WMS variant rollback: variant index drop skipped', ['error' => $e->getMessage()]);
            }
            try {
                Schema::table('product_locations', function (Blueprint $table) {
                    $table->dropColumn('product_variant_id');
                });
            } catch (Throwable $e) {
                Log::warning('WMS variant rollback: variant column drop skipped', ['error' => $e->getMessage()]);
            }
            try {
                Schema::table('inventory_operations', function (Blueprint $table) {
                    $table->dropForeign(['product_variant_id']);
                });
            } catch (Throwable $e) {
                Log::warning('WMS variant rollback: operations variant FK drop skipped', ['error' => $e->getMessage()]);
            }
            try {
                Schema::table('inventory_operations', function (Blueprint $table) {
                    $table->dropIndex('inventory_operations_variant_id_index');
                });
            } catch (Throwable $e) {
                Log::warning('WMS variant rollback: operations variant index drop skipped', ['error' => $e->getMessage()]);
            }
            try {
                Schema::table('inventory_operations', function (Blueprint $table) {
                    $table->dropColumn('product_variant_id');
                });
            } catch (Throwable $e) {
                Log::warning('WMS variant rollback: operations variant column drop skipped', ['error' => $e->getMessage()]);
            }
        }
    }
};

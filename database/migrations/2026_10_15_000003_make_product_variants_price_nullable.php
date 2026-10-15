<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variant rows without their own price fall back to the parent product.
 *
 * VERIFIED FACTS:
 * - App\Services\Checkout\OrderCreationService (createOrderItems) branches
 *   explicitly on `$variant->price !== null` (variant branch vs
 *   calculateProductPricing fallback branch).
 * - Marvel ProductVariant accessors (current_price/sale_price/final_price)
 *   are all written null-tolerant (array_key_exists + !== null).
 * - information_schema (catch + catch_verify): product_variants.price NOT
 *   NULL, no default — so a priceless variant cannot be persisted and the
 *   fallback branch is unreachable.
 * - OrderCreationFlowTest::variant_without_price_falls_back_to_product_price
 *   asserts the fallback (80.00 = 100 - 20%).
 *
 * Widening only (NOT NULL → nullable). Safe on existing rows (all prices
 * non-null); reversible via rollback while no NULL rows exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->double('price', 10, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->double('price', 10, 2)->nullable(false)->change();
        });
    }
};

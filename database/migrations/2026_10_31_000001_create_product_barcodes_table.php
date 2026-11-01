<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Piece/carton barcodes: ONE row per product (final approved design).
 *
 * - product_id is unique: at most one barcode record per product.
 * - piece_barcode / carton_barcode / pieces_per_carton are independently
 *   nullable. NULL means "unknown / not provided" — never faked to 0 or 1.
 * - Per-column unique indexes backstop duplicate values within a column.
 *   Cross-column collisions (a piece barcode equal to another product's
 *   carton barcode) and same-row piece == carton are enforced at the
 *   application level (App\Rules\UniqueProductBarcode +
 *   App\Services\Product\ProductBarcodeService::assertValid) because no
 *   portable single constraint covers them.
 * - FK deletion mirrors product_variants (2020_06_02_051901): CASCADE on
 *   hard delete. Products use SoftDeletes, so soft-deleting a product
 *   keeps its barcode row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete()->unique();
            $table->string('piece_barcode', 64)->nullable()->unique();
            $table->string('carton_barcode', 64)->nullable()->unique();
            $table->unsignedInteger('pieces_per_carton')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
    }
};

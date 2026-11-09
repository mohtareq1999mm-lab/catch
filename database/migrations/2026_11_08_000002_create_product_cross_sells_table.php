<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Directional manual cross-sell links between products.
 *
 * One row per directed relationship: `product_id` (source) →
 * `related_product_id` (recommended target). `A → B` never implies `B → A`.
 *
 * Serial 000002 (not 000001): the untracked external heroes migration uses
 * 2026_11_08_000001, so this avoids a timestamp tie (plan rev.3, Appendix C).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_cross_sells', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('related_product_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('related_product_id')->references('id')->on('products')->onDelete('cascade');
            $table->unique(['product_id', 'related_product_id']);
            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_cross_sells');
    }
};

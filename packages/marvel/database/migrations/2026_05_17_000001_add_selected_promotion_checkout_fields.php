<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('promotion_gift_products')) {
            Schema::create('promotion_gift_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
                $table->unsignedInteger('quantity')->default(1);
                $table->timestamps();

                $table->unique(['promotion_id', 'product_id']);
                $table->index('product_id');
                $table->index('product_variant_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_gift_products');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fulfillment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fulfillment_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_item_id')->constrained('order_products')->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('restrict');
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->onDelete('restrict');
            $table->foreignId('product_location_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('quantity', 15, 2);
            $table->decimal('quantity_picked', 15, 2)->default(0);
            $table->string('status', 30)->default('pending'); // pending, picking, picked, packed, cancelled
            $table->timestamp('picked_at')->nullable();
            $table->timestamp('packed_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['fulfillment_id', 'status']);
            $table->index(['order_item_id']);
            $table->index(['product_location_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fulfillment_items');
    }
};

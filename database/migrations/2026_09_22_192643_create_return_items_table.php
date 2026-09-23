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
        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained('return_requests')->onDelete('cascade');
            $table->foreignId('order_item_id')->constrained('order_products')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('fulfillment_item_id')->nullable()->constrained('fulfillment_items')->onDelete('set null');
            $table->integer('quantity_returned');
            $table->integer('quantity_approved')->default(0);
            $table->integer('quantity_restocked')->default(0);
            $table->string('condition', 30)->default('unknown'); // good, damaged, defective, wrong_item
            $table->text('inspection_notes')->nullable();
            $table->foreignId('restocked_location_id')->nullable()->constrained('locations')->onDelete('set null');
            $table->foreignId('product_location_id')->nullable()->constrained('product_locations')->onDelete('set null');
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('restocked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_items');
    }
};

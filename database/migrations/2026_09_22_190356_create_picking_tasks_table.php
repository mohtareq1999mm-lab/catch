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
        Schema::create('picking_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('fulfillment_batches')->onDelete('cascade');
            $table->foreignId('fulfillment_item_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_location_id')->constrained()->onDelete('restrict');
            $table->decimal('quantity_to_pick', 15, 2);
            $table->decimal('quantity_picked', 15, 2)->default(0);
            $table->string('status', 30)->default('pending'); // pending, picking, picked, skipped
            $table->integer('sequence')->default(0); // Picking order within batch
            $table->timestamp('picked_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'sequence']);
            $table->index(['fulfillment_item_id']);
            $table->index(['product_location_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('picking_tasks');
    }
};

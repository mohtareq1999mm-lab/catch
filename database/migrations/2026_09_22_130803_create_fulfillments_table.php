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
        Schema::create('fulfillments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained()->onDelete('restrict');
            $table->string('fulfillment_number', 50)->unique();
            $table->string('status', 30)->default('pending'); // pending, picking, packing, shipped, delivered, cancelled
            $table->string('priority', 20)->default('normal'); // high, normal, low
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null'); // Picker/packer user
            $table->timestamp('picking_started_at')->nullable();
            $table->timestamp('picking_completed_at')->nullable();
            $table->timestamp('packing_started_at')->nullable();
            $table->timestamp('packing_completed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['warehouse_id', 'status', 'priority']);
            $table->index(['assigned_to', 'status']);
            $table->index('fulfillment_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fulfillments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();

            $table->string('tracking_number', 100)->nullable()->unique();
            $table->string('courier', 50)->nullable();
            $table->string('status', 30)->default('pending');

            $table->string('shipping_method', 30)->nullable();
            $table->decimal('shipping_cost', 10, 3)->default(0);
            $table->string('currency', 3)->default('EGP');

            $table->json('origin_address')->nullable();
            $table->json('destination_address')->nullable();

            $table->json('items')->nullable();
            $table->decimal('total_weight', 10, 3)->nullable();
            $table->string('weight_unit', 10)->default('kg');

            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('order_id', 'idx_ship_order_id');
            $table->index('status', 'idx_ship_status');
            $table->index('tracking_number', 'idx_ship_tracking');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};

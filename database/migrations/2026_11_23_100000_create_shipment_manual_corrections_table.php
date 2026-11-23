<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_manual_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('timeline_event_id')->nullable()->constrained('order_tracking_events')->nullOnDelete();
            $table->string('old_status', 32);
            $table->string('new_status', 32);
            $table->text('reason');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['shipment_id', 'created_at'], 'idx_shipment_manual_correction_shipment');
            $table->index(['actor_id', 'created_at'], 'idx_shipment_manual_correction_actor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_manual_corrections');
    }
};

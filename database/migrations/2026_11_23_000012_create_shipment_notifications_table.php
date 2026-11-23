<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->foreignId('timeline_event_id')->constrained('order_tracking_events')->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('recipient_type', 32);
            $table->string('recipient_id', 191);
            $table->string('status', 32)->default('pending');
            $table->string('dedupe_key', 191)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['timeline_event_id', 'channel', 'recipient_type', 'recipient_id'],
                'shipment_notifications_dedupe_unique'
            );
            $table->index(['shipment_id', 'created_at'], 'shipment_notifications_shipment_created_idx');
            $table->index(['status', 'created_at'], 'shipment_notifications_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_notifications');
    }
};

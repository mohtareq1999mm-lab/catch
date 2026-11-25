<?php

use App\Enums\Shipping\ShippingOutboxStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('operation', 120);
            $table->string('aggregate_type', 60);
            $table->string('aggregate_id', 64);
            $table->string('idempotency_key', 191);
            $table->string('provider_code', 32)->nullable();
            $table->string('provider_mode', 16)->nullable();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->string('status', 16)->default(ShippingOutboxStatus::PENDING->value);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->unique(['operation', 'aggregate_type', 'aggregate_id', 'idempotency_key'], 'uq_shipping_outbox_operation_identity');
            $table->index(['status', 'available_at'], 'idx_shipping_outbox_recovery');
            $table->index(['aggregate_type', 'aggregate_id'], 'idx_shipping_outbox_aggregate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_outbox');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_cod_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_id')->nullable()->constrained('cod_settlement_statements')->nullOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->char('currency_code', 3);
            $table->unsignedTinyInteger('decimal_places');
            $table->bigInteger('original_collectible_minor');
            $table->bigInteger('old_collectible_minor');
            $table->bigInteger('new_collectible_minor');
            $table->string('reason', 255);
            $table->foreignId('actor_id')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('status', 32)->default('pending_approval');
            $table->string('idempotency_key', 191);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'uq_shipment_cod_adjustment_idempotency');
            $table->index(['order_id', 'status'], 'idx_shipment_cod_adjustment_order_status');
            $table->index(['statement_id', 'status'], 'idx_shipment_cod_adjustment_statement');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_cod_adjustments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable audit snapshot of validated Flow Input values per order.
 *
 * Definition lives in flow_inputs; the order's BUSINESS copies live in
 * their owning domains (orders.governorate_id, orders.origin_country_id,
 * ...). This table proves WHAT was submitted and validated UNDER WHICH
 * flow, key and context — it is never the system of record for business
 * data, only the validation audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_flow_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('order_flows')->restrictOnDelete();
            $table->string('input_key', 50);
            $table->json('value')->nullable();
            $table->string('context', 60)->default('checkout');
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'input_key', 'context'], 'order_flow_values_order_key_ctx_unique');
            $table->index(['order_id', 'context'], 'order_flow_values_order_ctx_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_flow_values');
    }
};

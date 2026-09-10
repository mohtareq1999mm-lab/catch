<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Derived metrics from orders (materialized projection, NOT source of truth)
            $table->unsignedInteger('completed_orders')->default(0);

            // Sum of qualifying order totals in immutable base currency
            // Qualification: status='completed' AND payment_status='payment-success'
            // This is gross order value (NOT net spend after refunds in Phase 1)
            $table->decimal('total_qualifying_order_value', 15, 2)->default(0.00);

            // First and last qualifying order timestamps
            $table->timestamp('first_order_at')->nullable();
            $table->timestamp('last_order_at')->nullable();

            // Number of coupons used (redeemed) lifetime
            $table->unsignedInteger('coupons_used')->default(0);

            // Last rebuild timestamp for staleness detection
            $table->timestamp('computed_at')->useCurrent();

            $table->timestamps();

            // One metrics record per user
            $table->unique('user_id');

            // Indexes for eligibility rule evaluation
            $table->index('completed_orders');
            $table->index('total_qualifying_order_value');
            $table->index('first_order_at');
            $table->index('coupons_used');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_metrics');
    }
};

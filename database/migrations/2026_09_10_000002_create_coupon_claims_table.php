<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Claim timestamp
            $table->timestamp('claimed_at')->useCurrent();

            // Eligibility snapshot at claim time (JSON)
            // Stores: completed_orders, total_qualifying_order_value, evaluated_rules, etc.
            $table->json('eligibility_snapshot')->nullable();

            $table->timestamps();

            // CRITICAL: Enforce one lifetime claim per user per coupon
            // This is the atomic concurrency guard
            $table->unique(['coupon_id', 'user_id']);

            // Index for user claim history queries
            $table->index('user_id');

            // Index for coupon claim count queries
            $table->index(['coupon_id', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_claims');
    }
};

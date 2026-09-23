<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable cross-run per-user coupon state. UNIQUE(coupon_id,user_id) is
     * the cross-run idempotency arbiter: retries, duplicate chunks, new runs
     * with the same tree version, and late eligibility all converge here.
     * A new tree_hash re-opens evaluation; transitions drive notification.
     */
    public function up(): void
    {
        Schema::create('coupon_distribution_user_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('tree_hash', 64);
            $table->enum('state', ['eligible', 'not_eligible', 'notified'])->default('not_eligible');
            $table->unsignedBigInteger('last_run_id')->nullable();
            $table->unsignedBigInteger('last_recipient_id')->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['coupon_id', 'user_id']);
            $table->index(['user_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_distribution_user_states');
    }
};

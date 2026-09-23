<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_distribution_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('coupon_distribution_runs')->cascadeOnDelete();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('tree_hash', 64);
            $table->enum('status', [
                'discovered',
                'eligible',
                'not_eligible',
                'notified',
                'failed_retryable',
                'failed_permanent',
                'duplicate_skipped',
            ])->default('discovered');
            $table->timestamp('notified_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            // ONLY uniqueness: one row per user per run. Cross-run dedupe is
            // owned by coupon_distribution_user_states, never by copying
            // this constraint across runs.
            $table->unique(['run_id', 'user_id']);
            $table->index(['coupon_id', 'user_id']);
            $table->index(['run_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_distribution_recipients');
    }
};

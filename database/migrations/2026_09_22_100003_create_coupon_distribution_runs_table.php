<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_distribution_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->enum('trigger_type', [
                'coupon_created',
                'coupon_activated',
                'targeting_changed',
                'user_registered',
                'address_changed',
                'order_completed',
                'manual',
            ]);
            $table->string('trigger_id', 191)->nullable();
            $table->char('tree_hash', 64);
            $table->string('dedupe_key', 191)->unique();
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->unsignedInteger('candidate_count')->default(0);
            $table->unsignedInteger('eligible_count')->default(0);
            $table->unsignedInteger('not_eligible_count')->default(0);
            $table->unsignedInteger('notified_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('duplicate_skipped_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['coupon_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_distribution_runs');
    }
};

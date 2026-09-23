<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_event_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 120)->index();
            $table->string('aggregate_type', 60)->default('coupon');
            $table->string('aggregate_id', 64)->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('distribution_run_id')->nullable()->index();
            $table->uuid('correlation_id')->index();
            $table->uuid('causation_id')->nullable();
            $table->enum('status', ['published', 'processing', 'completed', 'failed', 'retrying', 'dead_lettered'])->default('published');
            $table->string('queue', 120)->nullable();
            $table->string('routing_key', 160)->nullable();
            $table->string('consumer', 60)->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'event_type']);
            $table->index(['distribution_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_event_logs');
    }
};

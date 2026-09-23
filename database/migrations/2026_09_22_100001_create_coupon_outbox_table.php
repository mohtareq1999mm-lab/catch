<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 120)->index();
            $table->string('aggregate_type', 60)->default('coupon');
            $table->string('aggregate_id', 64)->nullable()->index();
            $table->uuid('correlation_id')->index();
            $table->uuid('causation_id')->nullable();
            $table->json('payload');
            $table->enum('status', ['pending', 'publishing', 'published', 'failed'])->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('published_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_outbox');
    }
};

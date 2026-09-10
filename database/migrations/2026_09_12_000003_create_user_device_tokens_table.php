<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Skip if table already exists with different schema (legacy device_tokens)
        // We create user_device_tokens as specified, distinct from existing device_tokens
        if (Schema::hasTable('user_device_tokens')) {
            return;
        }

        Schema::create('user_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');

            // Device info
            $table->string('token', 500)->comment('FCM/APNS token');
            $table->enum('platform', ['ios', 'android', 'web'])->default('web');
            $table->string('device_name')->nullable();
            $table->string('device_id')->nullable()->comment('Unique device identifier');

            // Status
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Metadata
            $table->string('app_version')->nullable();
            $table->string('os_version')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->unique(['user_id', 'token', 'platform'], 'unique_user_token_platform');
            $table->index(['user_id', 'is_active']);
            $table->index('device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_device_tokens');
    }
};

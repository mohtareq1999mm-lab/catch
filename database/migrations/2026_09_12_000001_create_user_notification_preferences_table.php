<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();

            // Channel enablement
            $table->boolean('email_enabled')->default(true);
            $table->boolean('sms_enabled')->default(true);
            $table->boolean('push_enabled')->default(true);
            $table->boolean('websocket_enabled')->default(true);

            // Event-specific preferences
            $table->json('event_preferences')->nullable()->comment('Per-event channel overrides');

            // Contact info verification
            $table->boolean('email_verified')->default(false);
            $table->boolean('phone_verified')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();

            // Quiet hours (UTC)
            $table->time('quiet_hours_start')->nullable()->comment('UTC time, e.g., 22:00');
            $table->time('quiet_hours_end')->nullable()->comment('UTC time, e.g., 08:00');
            $table->boolean('respect_quiet_hours')->default(false);

            // Locale
            $table->string('notification_language', 10)->default('en')->comment('ISO 639-1');

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');
    }
};

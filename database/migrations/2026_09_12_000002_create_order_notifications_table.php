<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id');

            // Notification details
            $table->string('event_type', 50)->comment('order_confirmed, order_shipped, etc.');
            $table->string('channel', 20)->comment('email, sms, push, websocket');
            $table->enum('status', ['pending', 'sent', 'delivered', 'failed', 'skipped'])->default('pending');

            // Content
            $table->string('subject')->nullable();
            $table->text('message')->nullable();

            // Delivery tracking
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();

            // Provider info
            $table->string('provider', 50)->nullable()->comment('twilio, ses, fcm, pusher');
            $table->string('provider_message_id')->nullable();
            $table->json('provider_response')->nullable();

            // Metadata
            $table->json('metadata')->nullable()->comment('Template vars, tracking params');

            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index(['order_id', 'channel', 'event_type']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'sent_at']);
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notifications');
    }
};

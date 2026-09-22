<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_tracking_events', function (Blueprint $table) {
            $table->id();
            
            // Core identification
            $table->unsignedBigInteger('order_id')->index();
            $table->string('event_type', 50)->index(); // order.created, payment.succeeded, etc.
            $table->timestamp('event_timestamp')->index();
            
            // Event context
            $table->string('actor_type', 50)->nullable(); // system, admin, customer, courier
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 100)->nullable();
            
            // Status tracking
            $table->string('old_status', 50)->nullable();
            $table->string('new_status', 50)->nullable()->index();
            
            // Event metadata (flexible JSON storage)
            $table->json('metadata')->nullable(); // payment_method, tracking_number, refund_amount, etc.
            
            // Customer-facing fields
            $table->boolean('customer_visible')->default(true)->index();
            $table->string('customer_label_key', 100); // translation key: tracking.order.created
            $table->text('customer_description_key')->nullable(); // translation key with placeholders
            
            // Admin-facing fields
            $table->text('admin_notes')->nullable();
            
            // Source tracking
            $table->string('source', 50)->default('system'); // webhook, admin_panel, api, system
            $table->string('ip_address', 45)->nullable();
            
            $table->timestamps();
            
            // Composite indexes for query optimization
            $table->index(['order_id', 'event_timestamp']);
            $table->index(['order_id', 'customer_visible']);
            
            // Foreign key
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_tracking_events');
    }
};

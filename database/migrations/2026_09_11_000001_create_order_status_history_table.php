<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');

            // Status tracking
            $table->string('old_status')->nullable(); // null for initial creation
            $table->string('new_status');
            $table->string('old_payment_status')->nullable();
            $table->string('new_payment_status')->nullable();
            $table->string('old_fulfillment_status')->nullable();
            $table->string('new_fulfillment_status')->nullable();

            // Who made the change
            $table->foreignId('changed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->string('changed_by_type')->default('user'); // user, system, admin, payment_gateway

            // Additional context
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable(); // extra data like gateway response, IP, etc.

            // Timestamps
            $table->timestamp('changed_at');
            $table->timestamps();

            // Indexes for fast queries
            $table->index(['order_id', 'changed_at']);
            $table->index('changed_by');
            $table->index('new_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};

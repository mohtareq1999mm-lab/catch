<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One entitlement per DIGITAL order-product line (D6).
     * UNIQUE(order_product_id) is the exactly-once idempotency anchor.
     */
    public function up(): void
    {
        Schema::create('digital_entitlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_product_id')->unique()->constrained('order_products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedInteger('download_limit')->default(5);
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'digital_entitlements_user_status_idx');
            $table->index('order_id', 'digital_entitlements_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_entitlements');
    }
};

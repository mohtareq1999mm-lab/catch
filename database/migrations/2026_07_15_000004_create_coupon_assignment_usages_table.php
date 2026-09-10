<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_assignment_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_assignment_id')->constrained('coupon_assignments')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('used_at')->useCurrent();
            $table->timestamps();

            $table->index('coupon_assignment_id');
            $table->index('created_at');
            $table->index(['coupon_assignment_id', 'created_at']);
            $table->unique(['coupon_assignment_id', 'order_id'], 'uq_coupon_assignment_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_assignment_usages');
    }
};

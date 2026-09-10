<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_status')->nullable()->after('status');
            $table->string('fulfillment_status')->nullable()->after('payment_status');
            $table->boolean('coupon_consumed')->default(false)->after('fulfillment_status');
            $table->boolean('promotion_consumed')->default(false)->after('coupon_consumed');
            $table->timestamp('paid_at')->nullable()->after('promotion_consumed');
            $table->timestamp('completed_at')->nullable()->after('paid_at');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_status',
                'fulfillment_status',
                'coupon_consumed',
                'promotion_consumed',
                'paid_at',
                'completed_at',
                'cancelled_at',
            ]);
        });
    }
};

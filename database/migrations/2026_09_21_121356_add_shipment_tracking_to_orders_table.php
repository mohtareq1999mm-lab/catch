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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipment_status')->nullable()->after('fulfillment_status');
            $table->string('tracking_number')->nullable()->after('shipment_status');
            $table->string('courier_name')->nullable()->after('tracking_number');
            $table->timestamp('estimated_delivery_at')->nullable()->after('courier_name');
            $table->timestamp('actual_delivery_at')->nullable()->after('estimated_delivery_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipment_status',
                'tracking_number',
                'courier_name',
                'estimated_delivery_at',
                'actual_delivery_at',
            ]);
        });
    }
};

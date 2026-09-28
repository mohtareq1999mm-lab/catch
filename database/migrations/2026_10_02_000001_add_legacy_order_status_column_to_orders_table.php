<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restore the legacy `orders.order_status` column expected by the Marvel
 * status traits (OrderManagementTrait, payment traits, analytics queries).
 *
 * No migration in this repository ever created it, so any legacy
 * changeOrderStatus() save fatals with "Unknown column 'order_status'".
 * Nullable string: legacy values are prefixed codes (e.g. order-processing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'order_status')) {
                $table->string('order_status', 50)->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('order_status');
        });
    }
};

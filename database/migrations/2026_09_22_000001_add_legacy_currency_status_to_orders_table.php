<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FINAL CLOSURE Sec 5 Case B: marks legacy orders whose historical
     * currency conversion cannot be reconstructed.
     *
     * Values: NULL = current/valid snapshot; 'unresolved' =
     * LEGACY_CURRENCY_UNRESOLVED (excluded from spend metrics until
     * remediation/business policy). Additive nullable column: SAFE.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('legacy_currency_status', 32)->nullable()->after('converted_total_price');
            $table->index('legacy_currency_status', 'orders_legacy_currency_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_legacy_currency_status_idx');
            $table->dropColumn('legacy_currency_status');
        });
    }
};

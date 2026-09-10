<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable()->after('product_total_price');
            $table->string('catalog_currency_code', 3)->nullable()->after('currency_code');
            $table->decimal('catalog_price', 8, 3)->nullable()->after('catalog_currency_code');
            $table->decimal('catalog_total_price', 8, 3)->nullable()->after('catalog_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'catalog_currency_code', 'catalog_price', 'catalog_total_price']);
        });
    }
};
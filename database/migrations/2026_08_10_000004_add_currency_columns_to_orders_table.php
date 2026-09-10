<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable()->after('total_price');
            $table->string('base_currency_code', 3)->nullable()->after('currency_code');
            $table->decimal('currency_rate', 20, 10)->nullable()->after('base_currency_code');
            $table->date('currency_rate_date')->nullable()->after('currency_rate');
            $table->decimal('converted_total_price', 10, 3)->nullable()->after('currency_rate_date');
        });

        DB::table('orders')
            ->whereNull('converted_total_price')
            ->update(['converted_total_price' => DB::raw('total_price')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'currency_code',
                'base_currency_code',
                'currency_rate',
                'currency_rate_date',
                'converted_total_price',
            ]);
        });
    }
};

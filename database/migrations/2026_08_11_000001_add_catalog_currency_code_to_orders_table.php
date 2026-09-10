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
            $table->string('catalog_currency_code', 3)->nullable()->after('currency_code');
        });

        DB::table('orders')
            ->whereNull('catalog_currency_code')
            ->update(['catalog_currency_code' => DB::raw('currency_code')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('catalog_currency_code');
        });
    }
};

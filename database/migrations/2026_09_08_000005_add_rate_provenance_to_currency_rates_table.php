<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currency_rates', function (Blueprint $table) {
            $table->string('source', 20)->default('legacy')->after('exchange_rate');
            $table->string('provider', 50)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('currency_rates', function (Blueprint $table) {
            $table->dropColumn(['source', 'provider']);
        });
    }
};

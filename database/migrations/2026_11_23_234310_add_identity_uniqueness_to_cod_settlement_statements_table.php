<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cod_settlement_statements', function (Blueprint $table) {
            $table->unique(
                ['provider_code', 'mode', 'account_code', 'period_start', 'period_end', 'currency_code'],
                'uq_cod_settlement_statement_identity'
            );
        });
    }

    public function down(): void
    {
        Schema::table('cod_settlement_statements', function (Blueprint $table) {
            $table->dropUnique('uq_cod_settlement_statement_identity');
        });
    }
};
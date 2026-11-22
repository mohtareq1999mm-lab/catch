<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #135: COD settlement statement evidence.
 *
 * Financial/shipping bookkeeping only. These rows are never a payment,
 * order, shipment, fulfillment, or inventory authority. Money is stored as
 * signed integer minor units plus the currency code and its resolved
 * decimal places; no conversion rate or fee/tax rule is stored or inferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cod_settlement_statements', function (Blueprint $table) {
            $table->id();
            $table->string('statement_key', 191)->unique('uq_cod_settlement_statement_key');
            $table->string('provider_code', 32);
            $table->string('mode', 16);
            $table->string('account_code', 64);
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency_code', 3);
            $table->unsignedTinyInteger('decimal_places');
            $table->bigInteger('expected_minor');
            $table->bigInteger('received_minor');
            $table->bigInteger('variance_minor');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['provider_code', 'mode', 'account_code'], 'idx_cod_settlement_identity');
            $table->index(['period_start', 'period_end'], 'idx_cod_settlement_period');
            $table->index('currency_code', 'idx_cod_settlement_currency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cod_settlement_statements');
    }
};

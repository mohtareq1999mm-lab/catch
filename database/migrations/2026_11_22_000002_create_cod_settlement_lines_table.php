<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #135: per-order COD settlement evidence lines.
 *
 * The order id is retained as identity only (no foreign key cascade): the
 * evidence row must survive later lifecycle cleanup. Provider/mode/account,
 * period, and currency identity are repeated explicitly so a line is still
 * interpretable when read on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cod_settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_id')
                ->constrained('cod_settlement_statements')
                ->cascadeOnDelete();
            $table->string('line_key', 191);
            $table->unsignedBigInteger('order_id');
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
            $table->timestamps();

            $table->unique(['statement_id', 'line_key'], 'uq_cod_settlement_line_key');
            $table->index('order_id', 'idx_cod_settlement_line_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cod_settlement_lines');
    }
};

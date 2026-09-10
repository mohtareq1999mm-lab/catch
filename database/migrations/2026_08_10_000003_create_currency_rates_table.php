<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->decimal('exchange_rate', 20, 10);
            $table->date('effective_date');
            $table->timestamps();

            $table->unique(['currency_id', 'effective_date'], 'currency_rates_currency_date_unique');
            $table->index('effective_date', 'currency_rates_effective_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
    }
};

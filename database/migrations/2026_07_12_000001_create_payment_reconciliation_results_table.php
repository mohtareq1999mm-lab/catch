<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions');
            $table->foreignId('order_id')->constrained('orders');
            $table->string('gateway', 50);
            $table->string('mismatch_type', 50);
            $table->text('expected_value')->nullable();
            $table->text('actual_value')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('mismatch_type');
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_results');
    }
};

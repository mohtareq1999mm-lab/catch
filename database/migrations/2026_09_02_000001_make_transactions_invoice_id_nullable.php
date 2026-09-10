<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Make transactions.invoice_id nullable to support COD/Cashier payments
     * that don't have a gateway invoice ID.
     *
     * For online payments, invoice_id contains the gateway's transaction/invoice ID.
     * For COD/Cashier payments, invoice_id should be NULL since there's no gateway.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('invoice_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->integer('invoice_id')->nullable(false)->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widen transactions.amount to 3 fractional digits.
     *
     * Evidence (MySQL 8.4, database `catch`): orders.total_price and
     * order_products money columns are DECIMAL(8,3) and the payment path
     * supports ISO-4217 exponent-3 currencies (KWD/BHD/JOD/OMR/TND via
     * CurrencyPrecision). transactions.amount was DECIMAL(10,2), so MySQL
     * rounded 13.255 KWD to 13.26 on write. That corrupts the stored
     * financial record and shifts PaymentRefundService math
     * (toMinorUnits(txn.amount) = 13260 instead of 13255).
     *
     * SQLite masks this (typeless storage), which is why the suite only
     * fails on MySQL. Widening is data-safe (no narrowing, no row loss).
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE transactions MODIFY amount DECIMAL(10,3) NULL');
    }

    /**
     * Reverse the widening. NOTE: any stored 3dp fractions round back to 2dp
     * on downgrade — restore from backup if exact 3dp history matters.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE transactions MODIFY amount DECIMAL(10,2) NULL');
    }
};

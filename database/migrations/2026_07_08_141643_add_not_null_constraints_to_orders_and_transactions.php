<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // ── Orders: handle existing nulls before adding constraints ──

        DB::statement("UPDATE orders SET fulfillment_type = 'delivery' WHERE fulfillment_type IS NULL");
        DB::statement("UPDATE orders SET payment_method = 'online' WHERE payment_method IS NULL");
        DB::statement("UPDATE orders SET payment_gateway = 'myfatoorah' WHERE payment_gateway IS NULL");
        DB::statement("UPDATE orders SET shipping_price = 0 WHERE shipping_price IS NULL");

        if ($driver !== 'sqlite') {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('fulfillment_type', 20)->default('delivery')->change();
                $table->string('payment_method', 30)->change();
                $table->string('payment_gateway', 50)->change();
                $table->decimal('shipping_price', 8, 3)->default(0)->change();
            });
        }

        // ── Transactions: handle existing nulls ──

        $nullUuids = DB::table('transactions')->whereNull('uuid')->get();
        foreach ($nullUuids as $row) {
            DB::table('transactions')
                ->where('id', $row->id)
                ->update(['uuid' => (string) Str::uuid()]);
        }

        DB::statement("UPDATE transactions
            SET amount = (SELECT total_price FROM orders WHERE orders.id = transactions.order_id)
            WHERE amount IS NULL");

        if ($driver !== 'sqlite') {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('uuid', 36)->change();
                $table->decimal('amount', 10, 2)->default(0)->change();
            });
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE orders MODIFY fulfillment_type VARCHAR(20) NULL");
        DB::statement("ALTER TABLE orders MODIFY payment_method VARCHAR(30) NULL");
        DB::statement("ALTER TABLE orders MODIFY payment_gateway VARCHAR(50) NULL");
        DB::statement("ALTER TABLE orders MODIFY shipping_price DECIMAL(8,3) NULL");

        DB::statement("ALTER TABLE transactions MODIFY uuid CHAR(36) NULL");
        DB::statement("ALTER TABLE transactions MODIFY amount DECIMAL(10,2) NULL");
    }
};

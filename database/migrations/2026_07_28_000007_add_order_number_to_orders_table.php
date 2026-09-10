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
            $table->string('order_number', 20)->nullable()->after('id');
        });

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement("UPDATE orders SET order_number = printf('ORD-%08d', id) WHERE order_number IS NULL");
        } else {
            DB::statement("UPDATE orders SET order_number = CONCAT('ORD-', LPAD(id, 8, '0')) WHERE order_number IS NULL");
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('order_number');
        });
    }
};

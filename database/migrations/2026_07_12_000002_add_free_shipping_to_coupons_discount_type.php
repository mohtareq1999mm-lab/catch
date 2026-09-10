<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            DB::statement("ALTER TABLE coupons MODIFY COLUMN discount_type ENUM('percentage', 'fixed_rate', 'free_shipping') NULL");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            DB::statement("ALTER TABLE coupons MODIFY COLUMN discount_type ENUM('percentage', 'fixed_rate') NULL");
        }
    }
};

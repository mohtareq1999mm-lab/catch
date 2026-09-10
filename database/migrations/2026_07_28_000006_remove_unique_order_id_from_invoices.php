<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $driver = DB::connection()->getDriverName();
            if ($driver !== 'sqlite') {
                $table->dropForeign(['order_id']);
                $table->dropUnique('uq_invoices_order_id');
                $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            }
            $table->index('order_id', 'idx_invoices_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $driver = DB::connection()->getDriverName();
            if ($driver !== 'sqlite') {
                $table->dropForeign(['order_id']);
                $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            }
            $table->dropIndex('idx_invoices_order_id');
            if ($driver !== 'sqlite') {
                $table->unique('order_id', 'uq_invoices_order_id');
            }
        });
    }
};

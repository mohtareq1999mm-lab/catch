<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cod_settlement_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Unmatched evidence rows legitimately keep order_id NULL. Forcing
        // NOT NULL made rollback fail on normal data; leave the column
        // nullable in that case instead of corrupting or discarding evidence.
        if (DB::table('cod_settlement_lines')->whereNull('order_id')->exists()) {
            return;
        }

        Schema::table('cod_settlement_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable(false)->change();
        });
    }
};

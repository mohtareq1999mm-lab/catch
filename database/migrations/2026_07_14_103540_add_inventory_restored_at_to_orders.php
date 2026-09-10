<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('inventory_restored_at')->nullable()->after('deleted_at')
                ->comment('Atomic guard: set when inventory is restored to prevent duplicate restoration on queue retry');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('inventory_restored_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 12: per-line sellable-restore tracking for partial returns.
     * restored_quantity = units already returned to central stock for this
     * line (via approved sellable restocks). Prevents double-restore between
     * partial returns and a later full-order restore.
     */
    public function up(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->integer('restored_quantity')->default(0)->after('product_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->dropColumn('restored_quantity');
        });
    }
};

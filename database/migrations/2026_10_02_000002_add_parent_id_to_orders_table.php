<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restore the legacy `orders.parent_id` column expected by the Marvel
 * Order model (`children()` hasMany) and status traits. Absent, any
 * legacy changeOrderStatus() call fatals when it probes for child orders.
 * Single-store orders simply carry NULL (no children).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'parent_id')) {
                $table->unsignedBigInteger('parent_id')->nullable()->after('id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('parent_id');
        });
    }
};

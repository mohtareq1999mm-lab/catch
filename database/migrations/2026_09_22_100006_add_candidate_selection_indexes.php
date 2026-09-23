<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Candidate-selection index gap (additive, non-destructive):
     * last_order_after/before rules filter on customer_metrics.last_order_at.
     */
    public function up(): void
    {
        Schema::table('customer_metrics', function (Blueprint $table) {
            $table->index('last_order_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_metrics', function (Blueprint $table) {
            $table->dropIndex(['last_order_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};

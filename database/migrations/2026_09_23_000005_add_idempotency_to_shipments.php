<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 11: idempotent shipment creation (safe dispatch retries).
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->unique()->after('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};

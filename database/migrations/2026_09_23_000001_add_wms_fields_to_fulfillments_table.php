<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6: fulfillment release hardening (additive only).
     * - idempotency_key: safe creation retries (unique per creation request).
     * - ready_to_ship_at: timestamp for the locked ready_to_ship state.
     */
    public function up(): void
    {
        Schema::table('fulfillments', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->unique()->after('fulfillment_number');
            $table->timestamp('ready_to_ship_at')->nullable()->after('packing_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('fulfillments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'ready_to_ship_at']);
        });
    }
};

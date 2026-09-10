<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W3 — entitlement-level access expiration (target-schema item).
     * Enforcement at access time is a later workstream; NULL keeps today's
     * behavior unchanged.
     */
    public function up(): void
    {
        Schema::table('digital_entitlements', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('digital_entitlements', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};

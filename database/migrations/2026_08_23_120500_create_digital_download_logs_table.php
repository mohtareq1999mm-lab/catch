<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail for digital downloads. IPs / user agents are stored as
     * salted hashes only — never raw values.
     */
    public function up(): void
    {
        Schema::create('digital_download_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entitlement_id')->constrained('digital_entitlements')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->string('ip_hash', 64)->nullable();
            $table->string('ua_hash', 64)->nullable();
            $table->timestamp('downloaded_at');

            $table->index(['entitlement_id', 'downloaded_at'], 'digital_download_logs_entitlement_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_download_logs');
    }
};

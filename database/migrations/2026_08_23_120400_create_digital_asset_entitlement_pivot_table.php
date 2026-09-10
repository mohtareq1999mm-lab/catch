<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links delivered entitlements to the concrete digital assets that were
     * granted at fulfillment time.
     */
    public function up(): void
    {
        Schema::create('digital_asset_entitlement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('digital_entitlement_id')->constrained('digital_entitlements')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->timestamp('granted_at')->useCurrent();

            $table->unique(['digital_entitlement_id', 'digital_asset_id'], 'digital_asset_entitlement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_asset_entitlement');
    }
};

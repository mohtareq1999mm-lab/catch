<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('shipments', 'provider_shipment_id')) {
                $table->string('provider_shipment_id', 191)->nullable()->after('tracking_number');
                $table->index('provider_shipment_id', 'idx_ship_provider_shipment_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (Schema::hasColumn('shipments', 'provider_shipment_id')) {
                $table->dropIndex('idx_ship_provider_shipment_id');
                $table->dropColumn('provider_shipment_id');
            }
        });
    }
};

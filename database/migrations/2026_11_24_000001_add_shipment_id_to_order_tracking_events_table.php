<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('order_tracking_events') || Schema::hasColumn('order_tracking_events', 'shipment_id')) {
            return;
        }

        Schema::table('order_tracking_events', function (Blueprint $table) {
            $table->foreignId('shipment_id')
                ->nullable()
                ->after('order_id')
                ->constrained('shipments')
                ->nullOnDelete();
            $table->index(['shipment_id', 'event_timestamp'], 'order_tracking_events_shipment_event_ts_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('order_tracking_events') || !Schema::hasColumn('order_tracking_events', 'shipment_id')) {
            return;
        }

        Schema::table('order_tracking_events', function (Blueprint $table) {
            $table->dropIndex('order_tracking_events_shipment_event_ts_idx');
            $table->dropConstrainedForeignId('shipment_id');
        });
    }
};

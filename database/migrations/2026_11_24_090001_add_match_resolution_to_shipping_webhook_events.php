<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_webhook_events', function (Blueprint $table) {
            if (! Schema::hasColumn('shipping_webhook_events', 'match_status')) {
                $table->string('match_status', 32)->nullable()->after('shipment_id');
            }
            if (! Schema::hasColumn('shipping_webhook_events', 'match_notes')) {
                $table->text('match_notes')->nullable()->after('match_status');
            }
        });

        // #161: order-number matching is an indexed lookup, not a per-event
        // full scan. Index is non-unique by design: duplicate order numbers
        // must remain representable so they can be classified as ambiguous.
        Schema::table('orders', function (Blueprint $table) {
            $table->index('order_number', 'orders_order_number_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_order_number_index');
        });

        Schema::table('shipping_webhook_events', function (Blueprint $table) {
            if (Schema::hasColumn('shipping_webhook_events', 'match_notes')) {
                $table->dropColumn('match_notes');
            }
            if (Schema::hasColumn('shipping_webhook_events', 'match_status')) {
                $table->dropColumn('match_status');
            }
        });
    }
};

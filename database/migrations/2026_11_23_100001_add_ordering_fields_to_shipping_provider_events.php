<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_provider_events', function (Blueprint $table) {
            $table->timestamp('occurred_at')->nullable()->after('raw_status');
            $table->timestamp('received_at')->nullable()->after('occurred_at');
            $table->string('ordering_key', 191)->nullable()->after('received_at');
            $table->index(['shipment_id', 'provider_code', 'mode', 'account_code', 'occurred_at'], 'idx_shipping_provider_event_ordering');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_provider_events', function (Blueprint $table) {
            $table->dropIndex('idx_shipping_provider_event_ordering');
            $table->dropColumn(['occurred_at', 'received_at', 'ordering_key']);
        });
    }
};

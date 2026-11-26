<?php

use App\Services\Shipment\ShippingInboxDuplicateAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #182: read-only duplicate audit of both shipping inbox tables, written to
 * the canonical activity log BEFORE the constraint is installed. If
 * duplicates exist the migration fails closed; no row is deleted or
 * rewritten. Settlement acceptance columns are provided by the lineage
 * migration (#179) and are intentionally not touched here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $audit = app(ShippingInboxDuplicateAudit::class);
        $audit->assertClean($audit->audit('migration'));

        Schema::table('shipping_provider_events', function (Blueprint $table): void {
            // The inbox already enforces this tuple on shipping_webhook_events.
            // Processed evidence must be scoped identically so an event id from
            // a different deployment account can never suppress a delivery.
            $table->unique(
                ['provider_code', 'mode', 'account_code', 'provider_event_key'],
                'uq_shipping_provider_event_identity',
            );
        });
    }

    public function down(): void
    {
        Schema::table('shipping_provider_events', function (Blueprint $table): void {
            $table->dropUnique('uq_shipping_provider_event_identity');
        });
    }
};

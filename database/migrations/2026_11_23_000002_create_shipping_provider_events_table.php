<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #151: provider-event evidence, deliberately separate from the canonical
 * order_tracking_events timeline. Every processed event retains the raw
 * status, the versioned map used, the mapped status (NULL on failure), and a
 * reference/hash of the redacted payload. This table has no lifecycle write
 * authority: transitions go through ShipmentService only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_provider_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_event_id')->constrained('shipping_webhook_events')->cascadeOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->string('provider_code', 32);
            $table->string('mode', 16);
            $table->string('account_code', 64);
            $table->string('provider_event_key', 191);
            $table->string('raw_status', 191)->nullable();
            $table->string('mapping_version', 32)->nullable();
            $table->string('mapped_status', 32)->nullable();
            $table->string('exception_classification', 32)->nullable();
            $table->string('payload_reference', 191);
            $table->char('payload_hash', 64);
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique('webhook_event_id', 'uq_shipping_provider_event_inbox');
            $table->index(['shipment_id', 'created_at'], 'idx_shipping_provider_event_shipment');
            $table->index(
                ['provider_code', 'mode', 'account_code', 'created_at'],
                'idx_shipping_provider_event_identity',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_provider_events');
    }
};

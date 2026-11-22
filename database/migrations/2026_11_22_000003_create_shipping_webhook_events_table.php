<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #143: durable carrier webhook inbox.
 *
 * One row per ACCEPTED, signature-verified delivery. The row is committed
 * before the provider is acknowledged; a duplicate unique-key hit is a
 * successful no-op, never a second processing pass. Only a redacted identity
 * projection is stored — no raw body, signature, or secret.
 *
 * This table is evidence only. It has no observers and grants no authority to
 * change shipment, order, fulfillment, payment, or inventory state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_code', 32);
            $table->string('mode', 16);
            $table->string('account_code', 64);
            $table->string('webhook_version', 16);
            $table->string('event_key', 191);
            $table->string('event_type', 64)->nullable();
            $table->string('provider_shipment_ref', 191)->nullable();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->json('redacted_payload');
            $table->string('status', 20)->default('received');
            $table->text('last_error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_code', 'event_key'], 'uq_shipping_webhook_event');
            $table->index(['provider_code', 'mode', 'account_code'], 'idx_shipping_webhook_identity');
            $table->index('shipment_id', 'idx_shipping_webhook_shipment');
            $table->index('status', 'idx_shipping_webhook_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_webhook_events');
    }
};

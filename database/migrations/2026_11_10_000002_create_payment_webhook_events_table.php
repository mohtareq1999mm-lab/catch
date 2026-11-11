<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable webhook event store (W-1).
 *
 * One row per ACCEPTED provider delivery, inserted only AFTER the delivery
 * passed provider authentication (signature / SDK verify / HMAC / JWT /
 * auth header). Guarantees:
 * - UNIQUE(provider, event_key): stable provider event id when the provider
 *   supplies one (Stripe event.id, PayPal body.id); otherwise a SCOPED
 *   fingerprint over verified, normalized identity fields
 *   (provider|account|object id|event type|status) — never raw bytes alone.
 * - status: received -> processed | failed (retriable) -> dead (retries
 *   exhausted; retained for ops replay; never auto-deleted).
 * - payload: REDACTED normalized projection only (event type, status,
 *   provider object id, attempt ref, amount, currency). No secrets, no
 *   customer PII, no full provider payloads.
 * - A delivery is acknowledged 2xx only after its row is durably committed;
 *   persistence failure returns 5xx so the provider retries (at-least-once).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_key', 191);
            $table->string('provider_account', 64)->nullable();
            $table->string('event_type', 64)->nullable();
            $table->string('provider_ref', 191)->nullable();
            $table->string('attempt_ref', 64)->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('received');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_key']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};

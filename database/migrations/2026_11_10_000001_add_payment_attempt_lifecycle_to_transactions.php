<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment Attempt lifecycle columns (PM-1 foundation / PM-5 / PM-6 / PM-7).
 *
 * attempt_state      — lifecycle marker for rows created by the attempt
 *                      machinery: 'claimed' (committed before provider
 *                      dispatch, provider id still unknown) or
 *                      'session_created' (authoritative provider id
 *                      persisted). NULL = legacy rows: resolution, reaper,
 *                      and callback behavior stay byte-identical.
 * dispatch_reference — pre-generated immutable unique attempt identity,
 *                      carried into provider payloads (Stripe metadata,
 *                      PayPal custom_id, Tabby order.reference_id, Tamara
 *                      order_reference_id, Tap reference.idempotent) and
 *                      echoed by authenticated webhooks so an event can be
 *                      correlated to the exact attempt BEFORE the provider
 *                      id is persisted (PM-6 Option A).
 * capture_state      — PayPal explicit-capture coordination (PM-7 / Durable
 *                      Capture Operation): NULL = n/a, 'requested' = intent
 *                      persisted (queued), 'processing' = atomically claimed,
 *                      lease active (capture_attempted_at = lease stamp),
 *                      'succeeded' = provider-confirmed capture, 'failed' =
 *                      provider-confirmed definitive denial, 'unknown' =
 *                      ambiguous outcome (reconcile via read-only probe
 *                      before any re-capture; never elapsed-time failure).
 *
 * transactions.invoice_id is ALREADY nullable (2026_09_02_000001), so claim
 * rows (no provider reference yet) are insertable without further changes.
 *
 * transactions.user_id becomes nullable (approved B-1) so future guest
 * attempts can persist without an owning user; the order remains the
 * primary business reference. dbal 3.7.1 ->change() is used.
 *
 * Rollback: dropping the additive columns is safe. Re-applying NOT NULL on
 * user_id is DATA-AFFECTING once NULL rows exist (one-way door, documented).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Columns and their companion index are added together; the
        // attempt_state presence check makes the migration idempotent.
        if (!Schema::hasColumn('transactions', 'attempt_state')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('attempt_state', 20)->nullable()->after('status');
                $table->string('dispatch_reference', 64)->nullable()->unique()->after('attempt_state');
                $table->string('capture_state', 20)->nullable()->after('dispatch_reference');
                $table->timestamp('capture_attempted_at')->nullable()->after('capture_state');

                // Resolver/worker query pattern: unresolved attempts per order.
                $table->index(['order_id', 'status'], 'txn_order_status_idx');
            });
        }

        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'user_id')) {
                $table->bigInteger('user_id')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('transactions', 'capture_attempted_at')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropIndex('txn_order_status_idx');
                $table->dropColumn(['attempt_state', 'dispatch_reference', 'capture_state', 'capture_attempted_at']);
            });
        }

        // DATA-AFFECTING: only safe while no NULL user_id rows exist.
        Schema::table('transactions', function (Blueprint $table) {
            $table->bigInteger('user_id')->nullable(false)->change();
        });
    }
};

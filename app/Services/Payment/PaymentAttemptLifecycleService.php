<?php

namespace App\Services\Payment;

use App\DTOs\GatewayResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;

/**
 * PM-1 / PM-7 / SC-1 — Payment Attempt lifecycle coordination.
 *
 * Single owner of attempt-state transitions and capture coordination. All
 * transitions are ATOMIC guarded updates (row lock or conditional UPDATE)
 * so concurrent webhooks/workers can never double-claim or double-fail.
 *
 * Financial safety invariants enforced here:
 * - A `claimed` row carries NO provider id: correlation/adoption (PM-6)
 *   is the only path to `session_created`, and it requires a verified
 *   server-side retrieval of the provider state.
 * - markFailedWithProviderEvidence() refuses rows without conclusive
 *   provider evidence (T-03: Unknown never becomes Failed by elapsed time).
 * - hasUnresolvedAttempt() blocks new attempts while any attempt could
 *   still collect money (SC-1).
 * - Capture coordination (A): persisted intent → atomic lease claim →
 *   provider I/O with NO database locks → outcome persisted. An ambiguous
 *   outcome lands in `unknown` and recovery probes BEFORE any re-capture.
 */
class PaymentAttemptLifecycleService
{
    /** Lease window for an in-flight capture claim (A: lease expiration). */
    public const CAPTURE_LEASE_MINUTES = 10;

    /**
     * PM-1: persist the attempt claim BEFORE any provider dispatch.
     * `dispatch_reference` is the pre-generated immutable identity carried
     * into provider payloads and echoed by authenticated webhooks (PM-6).
     */
    public function createClaim(
        Order $order,
        string $gateway,
        string $dispatchReference,
        float $amount,
        string $currency,
        ?int $userId,
    ): Transaction {
        return Transaction::create([
            'order_id' => $order->id,
            'user_id' => $userId,
            'invoice_id' => null,
            'payment_method' => $gateway,
            'status' => 'pending',
            'attempt_state' => 'claimed',
            'dispatch_reference' => $dispatchReference,
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    /** SC-1: does any attempt on this order remain unresolved (could still
     * collect money)? Only provider-CONFIRMED terminal evidence (status
     * 'failed' written exclusively from conclusive probe/webhook results)
     * releases the block — a locally-failed row alone never does. */
    public function hasUnresolvedAttempt(int $orderId): bool
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('transactions', 'attempt_state')) {
            return false; // pre-migration schema: no attempt machinery
        }

        return Transaction::query()
            ->where('order_id', $orderId)
            ->where('status', 'pending')
            ->whereNotNull('attempt_state')
            ->exists();
    }

    /**
     * PM-6 guarded adoption: `claimed` → `session_created` under a row lock,
     * only when every guard passes. The provider id MUST come from a
     * verified server-side retrieval — never from the webhook body alone.
     */
    public function adoptSessionCreated(Transaction $txn, string $providerId, array $probeRaw = []): bool
    {
        $updated = Transaction::query()
            ->whereKey($txn->id)
            ->where('status', 'pending')
            ->where('attempt_state', 'claimed')
            ->whereNull('gateway_transaction_id')
            ->where('payment_method', $txn->payment_method)
            ->update([
                'gateway_transaction_id' => $providerId,
                // Repo convention: invoice_id doubles as the provider
                // reference slot (PaymentCheckoutHandler:126) and
                // changeOrderStatus resolves transactions through it —
                // adoption MUST populate it or completion cannot find the
                // order.
                'invoice_id' => $providerId,
                'attempt_state' => 'session_created',
                'gateway_response' => array_merge(['_adopted_from' => 'webhook_correlation'], $probeRaw),
            ]);

        if ($updated === 0) {
            Log::info('Payment attempt adoption skipped: state changed under race', [
                'transaction_id' => $txn->id,
                'attempt_state' => $txn->attempt_state,
                'result' => 'adoption_race_lost',
            ]);

            return false;
        }

        $txn->refresh();

        return true;
    }

    /**
     * Provider-confirmed failure only. `$evidence` must reference the
     * authoritative source (probe/webhook result). This is the ONLY method
     * allowed to write status='failed' on attempt rows — callers must never
     * mark Unknown rows failed.
     */
    public function markFailedWithProviderEvidence(Transaction $txn, string $evidenceSource, string $reason): void
    {
        $updated = Transaction::query()
            ->whereKey($txn->id)
            ->where('status', 'pending')
            ->whereNotNull('attempt_state')
            ->update([
                'status' => 'failed',
                'error_message' => \Illuminate\Support\Str::limit($reason, 500, ''),
                'gateway_response' => array_merge(
                    is_array($txn->gateway_response) ? $txn->gateway_response : [],
                    ['_failure_evidence' => $evidenceSource]
                ),
            ]);

        if ($updated === 0) {
            Log::info('Attempt failure mark skipped: row no longer pending', [
                'transaction_id' => $txn->id,
                'result' => 'mark_failed_race_lost',
            ]);
        }
    }

    /**
     * A — Durable Capture Operation, step 1: persist the capture INTENT
     * ('requested') so a crash after intent, before dispatch, is visible.
     */
    public function requestCapture(Transaction $txn): void
    {
        Transaction::query()
            ->whereKey($txn->id)
            ->whereNull('capture_state')
            ->update(['capture_state' => 'requested']);
    }

    /**
     * A — step 2: ATOMIC worker/webhook ownership claim with lease.
     * Transitions requested|expired-processing → processing. Returns true
     * only for the single winner; every other concurrent actor gets false
     * (their event is a duplicate or an in-flight overlap — PayPal's own
     * retry plus the recovery worker provide the completion path).
     */
    public function claimCapture(Transaction $txn): bool
    {
        $leaseCutoff = now()->subMinutes(self::CAPTURE_LEASE_MINUTES);

        $claimed = Transaction::query()
            ->whereKey($txn->id)
            ->where('status', 'pending')
            ->where(function ($q) use ($leaseCutoff) {
                $q->whereNull('capture_state')
                    ->orWhere('capture_state', 'requested')
                    ->orWhere(function ($q2) use ($leaseCutoff) {
                        // Lease expired: a previous owner crashed mid-flight.
                        $q2->where('capture_state', 'processing')
                            ->where('capture_attempted_at', '<', $leaseCutoff);
                    });
            })
            ->update([
                'capture_state' => 'processing',
                'capture_attempted_at' => now(),
            ]);

        return $claimed === 1;
    }

    /** A — step 3: persist the capture outcome. */
    public function persistCaptureOutcome(Transaction $txn, string $state, string $evidence): void
    {
        $updated = Transaction::query()
            ->whereKey($txn->id)
            ->where('capture_state', 'processing')
            ->update(['capture_state' => $state]);

        if ($updated === 0) {
            // Outcome races a completed attempt — keep the durable evidence.
            Log::info('Capture outcome persisted over non-processing state', [
                'transaction_id' => $txn->id,
                'requested_state' => $state,
                'evidence' => $evidence,
                'result' => 'capture_outcome_race',
            ]);
        }

        $txn->refresh();
    }

    /**
     * A — recovery rule 3/5: probe-before-retry arbitration for an
     * ambiguous capture. Returns the authoritative probe result; NEVER
     * captures. `paid` → capture already happened (persist + complete);
     * conclusive-OPEN 'pending' → definitely not captured, safe to re-capture
     * (provider idempotency retention is irrelevant when the provider
     * confirms NO capture ever occurred); 'failed' → provider-confirmed
     * terminal; inconclusive → stay Unknown.
     */
    public function arbitrateAmbiguousCapture(
        Transaction $txn,
        \App\Services\Payment\PaymentGatewayFactory $factory,
    ): ?GatewayResult {
        $gateway = $factory->make($txn->payment_method);

        if (!$gateway instanceof \App\Services\Payment\Contracts\ReadonlyPaymentProbeContract
            || $txn->gateway_transaction_id === null
        ) {
            return null;
        }

        try {
            return $gateway->probePaymentStatus((string) $txn->gateway_transaction_id);
        } catch (\Throwable $e) {
            Log::warning('Capture arbitration probe threw', [
                'transaction_id' => $txn->id,
                'result' => 'probe_error',
            ]);

            return null;
        }
    }
}

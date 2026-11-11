<?php

namespace App\Console\Commands;

use App\DTOs\GatewayResult;
use App\Services\Payment\PaymentAttemptLifecycleService;
use App\Services\Payment\PaymentCapabilityRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Transaction;

/**
 * OP-1 / PM-7 recovery / SC-1 investigation / T-03 worker.
 *
 * Idempotent reconciliation worker for payment attempts. Cadence: every
 * 10 minutes (Kernel schedule, withoutOverlapping + onOneServer).
 *
 * What it does:
 *  1. Resolves attempt rows with a known provider id via a STRICTLY
 *     read-only probe: paid → canonical completion (completeLocked);
 *     provider-confirmed failed → provider-evidence failure; conclusive-open
 *     → kept Unknown (session may still collect). Never age-based.
 *  2. PayPal APPROVED rows → durable capture coordination (intent → atomic
 *     lease claim → explicit capturePayment → outcome persisted), shared
 *     with the webhook path — one logical capture, lease-arbitrated.
 *  3. Claimed rows (no provider id): can't be probed — surfaced to the
 *     operational queue (never guessed, never age-failed).
 *  4. SC-1 investigation items: cancelled/superseded orders whose attempt
 *     session is still open (could still collect) → throttled alert.
 *  5. Alerts: unresolved attempts older than 24h (throttled, per gateway).
 *     Elapsed time NEVER mutates payment state — alerting only.
 */
class ReconcilePaymentAttempts extends Command
{
    protected $signature = 'payments:reconcile-attempts';

    protected $description = 'Reconcile unresolved payment attempts via read-only probes; recover PayPal captures; surface SC-1 investigations (OP-1).';

    private const ALERT_THRESHOLD_HOURS = 24;

    public function handle(
        \App\Services\Payment\PaymentGatewayFactory $factory,
        PaymentAttemptLifecycleService $lifecycle,
        \App\Services\Payment\PaymentCompletionService $completion,
    ): int {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('transactions', 'attempt_state')) {
            $this->info('Attempt schema absent — nothing to reconcile.');

            return self::SUCCESS;
        }

        $started = microtime(true);

        $stats = [
            'completed' => 0, 'failed_confirmed' => 0, 'capture_recovered' => 0,
            'capture_claim_lost' => 0, 'kept_open' => 0, 'inconclusive' => 0,
            'claimed_queue' => 0, 'sc1_investigations' => 0, 'probes_errored' => 0,
        ];

        $rows = Transaction::query()
            ->where('status', 'pending')
            ->whereNotNull('attempt_state')
            ->with('order')
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $txn) {
            $order = $txn->order;

            if (!$order) {
                continue;
            }

            try {
                if ($txn->gateway_transaction_id === null) {
                    // Claimed, never dispatched / response lost: no probe
                    // possible without provider evidence — ops queue.
                    $stats['claimed_queue']++;
                    $this->alertIfStale($txn, 'claimed_no_provider_reference');
                    continue;
                }

                $gateway = $this->makeGateway($factory, (string) $txn->payment_method);

                if ($gateway === null || !$gateway instanceof \App\Services\Payment\Contracts\ReadonlyPaymentProbeContract) {
                    $stats['probes_errored']++;
                    continue;
                }

                $probe = $gateway->probePaymentStatus((string) $txn->gateway_transaction_id);
                $providerStatus = is_array($probe->rawResponse)
                    ? (string) ($probe->rawResponse['_probe_provider_status'] ?? '')
                    : '';

                // PM-7 recovery: PayPal approved-but-not-captured.
                if ($txn->payment_method === 'paypal'
                    && strtolower((string) $order->status) === 'pending'
                    && $probe->success
                    && ($probe->status ?? null) === 'pending'
                    && $providerStatus === 'APPROVED'
                    && $gateway instanceof \App\Services\Payment\Contracts\CapturingGatewayContract
                ) {
                    if ($lifecycle->claimCapture($txn)) {
                        $capture = $gateway->capturePayment((string) $txn->gateway_transaction_id);

                        if ($capture->success && ($capture->status ?? null) === 'paid') {
                            $lifecycle->persistCaptureOutcome($txn, 'succeeded', 'recovery_worker');
                            $stats['capture_recovered'] += $this->completeAttempt($txn, $order, $capture, $completion, $lifecycle);
                            continue;
                        }

                        // Ambiguous: probe arbitrates next sweep (never blind
                        // re-capture — provider idempotency retention guard).
                        $lifecycle->persistCaptureOutcome($txn, 'unknown', 'recovery_capture_ambiguous');
                        $stats['kept_open']++;
                        continue;
                    }

                    $stats['capture_claim_lost']++;
                    continue;
                }

                if ($probe->success && ($probe->status ?? null) === 'paid') {
                    $stats['completed'] += $this->completeAttempt($txn, $order, $probe, $completion, $lifecycle);
                    continue;
                }

                if (!$probe->success && ($probe->status ?? null) === 'failed') {
                    $lifecycle->markFailedWithProviderEvidence(
                        $txn,
                        'probe:' . $txn->payment_method,
                        (string) ($probe->errorMessage ?? 'Provider-confirmed failure')
                    );
                    $stats['failed_confirmed']++;
                    continue;
                }

                if ($probe->success && ($probe->status ?? null) === 'pending') {
                    $stats['kept_open']++;
                    $this->alertIfStale($txn, 'session_still_open');

                    // SC-1 investigation: cancelled/superseded order whose
                    // provider session could STILL collect money.
                    if (strtolower((string) $order->status) !== 'pending') {
                        $stats['sc1_investigations']++;
                        $this->sc1Alert($txn);
                    }

                    continue;
                }

                $stats['inconclusive']++;
            } catch (\Throwable $e) {
                report($e);
                $stats['probes_errored']++;
            }
        }

        $duration = round(microtime(true) - $started, 2);

        Log::info('payments:reconcile-attempts finished', $stats + ['duration_seconds' => $duration]);

        $this->table(array_keys($stats), [array_values($stats)]);

        return self::SUCCESS;
    }

    /** Canonical completion through the SOLE completion authority. */
    private function completeAttempt(
        Transaction $txn,
        $order,
        GatewayResult $result,
        \App\Services\Payment\PaymentCompletionService $completion,
        PaymentAttemptLifecycleService $lifecycle,
    ): int {
        return (int) DB::transaction(function () use ($txn, $order, $result, $completion) {
            $locked = Transaction::query()->whereKey($txn->id)->lockForUpdate()->first();

            if (!$locked || $locked->status !== 'pending') {
                return 0;
            }

            $lockedOrder = $locked->order()->lockForUpdate()->first();

            $outcome = $completion->completeLocked($locked, $lockedOrder, $result);

            if ($outcome === \App\Services\Payment\PaymentCompletionOutcome::Processed) {
                $fresh = $lockedOrder?->fresh();

                if ($fresh) {
                    event(new \App\Events\PaymentSucceeded($fresh));
                }

                return 1;
            }

            return 0;
        });
    }

    private function makeGateway($factory, string $name): ?object
    {
        try {
            return $factory->make($name);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** OP-1 alert: 24h threshold, per-gateway + state deduplicated (1h). */
    private function alertIfStale(Transaction $txn, string $state): void
    {
        if ($txn->created_at === null || $txn->created_at->diffInHours(now()) < self::ALERT_THRESHOLD_HOURS) {
            return;
        }

        $key = 'payments:reconcile-alert:' . $txn->payment_method . ':' . $state;

        if (Cache::has($key)) {
            return;
        }

        Cache::put($key, true, now()->addHour());

        Log::warning('Unresolved payment attempt exceeded alert threshold', [
            'transaction_id' => $txn->id,
            'order_id' => $txn->order_id,
            'gateway' => $txn->payment_method,
            'state' => $state,
            'age_hours' => (int) $txn->created_at->diffInHours(now()),
            'attempt_state' => $txn->attempt_state,
            'result' => 'ops_alert',
        ]);
    }

    /** SC-1: throttled investigation item — session may still collect. */
    private function sc1Alert(Transaction $txn): void
    {
        $key = 'payments:sc1-investigation:' . $txn->id;

        if (Cache::has($key)) {
            return;
        }

        Cache::put($key, true, now()->addHours(6));

        Log::warning('SC-1 investigation: open provider session on non-payable order', [
            'transaction_id' => $txn->id,
            'order_id' => $txn->order_id,
            'gateway' => $txn->payment_method,
            'gateway_transaction_id' => $txn->gateway_transaction_id,
            'result' => 'sc1_investigation',
        ]);
    }
}

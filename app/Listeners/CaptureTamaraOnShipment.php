<?php

namespace App\Listeners;

use App\Events\Fulfillment\FulfillmentProgressed;
use App\Models\Fulfillment\Fulfillment;
use App\Services\Payment\Contracts\AuthorisationGatewayContract;
use App\Services\Payment\PaymentCurrencyResolver;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;

/**
 * Tamara shipment-linked capture (POST /payments/capture).
 *
 * WHY HERE: Tamara's money obligation completes at `authorised` (the
 * canonical completion service runs once); settlement is a LATER provider
 * operation tied to the actual shipment ("NOT captured = NOT settled" —
 * docs.tamara.co/reference/captureorder). `FulfillmentProgressed` with a
 * shipped/delivered milestone is the verified "actually shipped" signal
 * (FulfillmentOrderSyncService::announce → after-commit event);
 * App\Events\OrderShipped is dead code and is deliberately NOT relied on
 * and NOT extended with a second shipped event.
 *
 * CAPTURE POLICY (lean, no invented business rules):
 * - FULL-SHIPMENT GATE: capture only when EVERY non-cancelled fulfillment
 *   of the order is shipped/delivered — the same aggregate tier the
 *   fulfillment-sync matrix uses for the order-level `shipped` state.
 *   Partial-shipment capture semantics are NOT invented here: a partial
 *   milestone simply defers (each later shipped/delivered milestone
 *   re-fires the check, converging when the last shipment leaves).
 * - CAPTURE AMOUNT: the order's authoritative total (total_price) — for a
 *   freshly authorised, uncaptured provider order this IS the remaining
 *   delta. Any divergence from the provider state (prior partial capture,
 *   cancelled amounts, moved state) is fenced by
 *   TamaraGateway::captureFromShipment's FRESH-READ pre-fence (never a
 *   blind resend — the provider exposes no capture idempotency key), so
 *   re-fires and duplicate workers cannot double-capture: the adapter
 *   refuses on any already-captured money and stays pending on ambiguity.
 * - Capture NEVER touches order/transaction completion state: success
 *   appends a `_captures` ledger entry only (bookkeeping, mirrors
 *   `_refunds`).
 *
 * Payment-row gates: tamara tx paid/partially_refunded with a provider id,
 * non-cancelled order. Everything else logs a visible, honest skip. A
 * pending capture outcome (provider ambiguity) is logged as the visible ops
 * gap it is — later milestones re-fire, and Tamara's documented 21-day
 * auto-capture plus the operator runbook are the backstops. Failures that
 * rethrow (none expected; adapter reports refusals as results, not
 * exceptions) would retry via the queue's bounded backoff.
 */
class CaptureTamaraOnShipment implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    public $afterCommit = true;

    public $tries = 5;

    public $backoff = [10, 30, 60, 120, 300];

    public function __construct(
        private PaymentGatewayFactory $gatewayFactory,
        private PaymentCurrencyResolver $currencyResolver,
    ) {}

    public function handle(FulfillmentProgressed $event): void
    {
        // Wake-ups on shipped/delivered only: both aggregate into "the goods
        // have left" tiers; a missed earlier milestone converges on the later
        // one. Everything else (picking/packing progress) is a no-op here.
        if (!in_array($event->milestone, ['shipped', 'delivered'], true)) {
            return;
        }

        $order = Order::query()->find($event->orderId);

        if (!$order) {
            Log::warning('Tamara capture skipped: order missing', [
                'order_id' => $event->orderId,
                'milestone' => $event->milestone,
            ]);

            return;
        }

        $transaction = $order->transactions()
            ->where('payment_method', 'tamara')
            // Only rows that already represent settled-in money are capture
            // candidates: a later failed/pending attempt row (same order,
            // same gateway id family) must never shadow the paid row the
            // fresh-read pre-fence should inspect.
            ->whereIn('status', ['paid', 'partially_refunded'])
            ->whereNotNull('gateway_transaction_id')
            ->latest()
            ->first();

        if (!$transaction
            || !in_array($transaction->status, ['paid', 'partially_refunded'], true)
            || !$transaction->gateway_transaction_id
        ) {
            Log::info('Tamara capture skipped: no captured-eligible payment row', [
                'order_id' => $event->orderId,
                'milestone' => $event->milestone,
                'result' => 'skipped',
            ]);

            return;
        }

        if ((string) $order->status === 'cancelled') {
            Log::info('Tamara capture skipped: order cancelled', [
                'order_id' => $event->orderId,
                'transaction_id' => $transaction->id,
            ]);

            return;
        }

        // FULL-SHIPMENT GATE (class docblock): every non-cancelled
        // fulfillment shipped/delivered, and at least one exists.
        $liveStatuses = Fulfillment::where('order_id', $order->getKey())
            ->where('status', '!=', 'cancelled')
            ->pluck('status')
            ->all();

        if ($liveStatuses === [] || array_diff($liveStatuses, ['shipped', 'delivered']) !== []) {
            Log::info('Tamara capture deferred: fulfillment not fully shipped yet', [
                'order_id' => $event->orderId,
                'milestone' => $event->milestone,
                'fulfillments' => $liveStatuses,
            ]);

            return;
        }

        $gatewayTransactionId = trim((string) $transaction->gateway_transaction_id);

        if ($gatewayTransactionId === '' || strlen($gatewayTransactionId) > 191 || !preg_match('/^[A-Za-z0-9\-_]+$/', $gatewayTransactionId)) {
            Log::warning('Tamara capture skipped: invalid gateway transaction id', [
                'order_id' => $event->orderId,
                'transaction_id' => $transaction->id,
            ]);

            return;
        }

        try {
            $adapter = $this->gatewayFactory->make('tamara');
        } catch (Throwable $e) {
            Log::warning('Tamara capture skipped: adapter unavailable', [
                'order_id' => $event->orderId,
                'transaction_id' => $transaction->id,
            ]);

            return;
        }

        if (!$adapter instanceof AuthorisationGatewayContract) {
            return;
        }

        $orderCurrency = $this->currencyResolver->forOrder($order);

        if (!$adapter->supportsCurrency($orderCurrency)) {
            Log::info('Tamara capture skipped: unsupported order currency', [
                'order_id' => $event->orderId,
                'currency' => $orderCurrency,
            ]);

            return;
        }

        $shippedAtValue = $event->fulfillmentId !== null
            ? Fulfillment::whereKey((int) $event->fulfillmentId)->value('shipped_at')
            : null;

        if ($shippedAtValue instanceof \DateTimeInterface) {
            $shippedAt = $shippedAtValue->format(DATE_ATOM);
        } elseif (is_string($shippedAtValue) && trim($shippedAtValue) !== '') {
            $shippedAt = trim($shippedAtValue);
        } else {
            $shippedAt = now()->toIso8601String();
        }

        $result = $adapter->captureFromShipment($order, $gatewayTransactionId, [
            'fulfillment_id' => $event->fulfillmentId,
            'shipped_at' => $shippedAt,
            'shipping_company' => (string) ($order->courier_name ?? 'Standard'),
            'tracking_number' => $order->tracking_number,
            'items' => [
                [
                    'reference_id' => mb_substr((string) ($order->order_number ?? $order->id), 0, 100),
                    'sku' => mb_substr((string) ($order->order_number ?? $order->id), 0, 128),
                    'name' => mb_substr(strip_tags('Order ' . (string) ($order->order_number ?? $order->id)), 0, 255),
                    'quantity' => 1,
                    'unit_price' => (float) $order->total_price,
                    'tax_amount' => 0.0,
                    'discount_amount' => 0.0,
                    'total_amount' => (float) $order->total_price,
                ],
            ],
        ]);

        if ($result->success) {
            $captureId = is_array($result->rawResponse)
                ? ($result->rawResponse['_capture_id'] ?? ($result->rawResponse['capture_id'] ?? null))
                : null;

            $this->appendLedger($transaction, is_string($captureId) ? $captureId : null, $event->fulfillmentId, $event->milestone, (float) $order->total_price, $orderCurrency);

            Log::info('Tamara capture recorded', [
                'order_id' => $event->orderId,
                'transaction_id' => $transaction->id,
                'milestone' => $event->milestone,
                'fulfillment_id' => $event->fulfillmentId,
                'result' => 'captured',
            ]);

            return;
        }

        $message = trim((string) ($result->errorMessage ?? ''));

        if (str_contains($message, 'already exists')) {
            // A prior capture already exists provider-side (re-fire or
            // concurrent worker). Honest benign dedupe, no throw.
            Log::info('Tamara capture skipped: capture already exists provider-side', [
                'order_id' => $event->orderId,
                'transaction_id' => $transaction->id,
                'milestone' => $event->milestone,
            ]);

            return;
        }

        // Pending/refused capture: VISIBLE ops gap (money not yet settled).
        // Later milestones re-fire; Tamara's 21-day auto-capture and the ops
        // runbook are the documented backstops. Never a throw — bounded
        // retries of a fresh-read-proven refusal only repeat the same
        // refusal and would merge ops signal into queue noise.
        Log::warning('Tamara capture not confirmed', [
            'order_id' => $event->orderId,
            'transaction_id' => $transaction->id,
            'milestone' => $event->milestone,
            'result' => is_string($result->status) ? $result->status : 'pending',
            'reason' => $message !== '' ? $message : 'not confirmed',
        ]);
    }

    /**
     * `_captures` ledger append (bookkeeping only — never completion
     * state): deduped per capture_id under the transaction row lock, using
     * provider-verified evidence (TamaraGateway returned the capture id
     * only after its own provider confirmation).
     */
    private function appendLedger(
        Transaction $transaction,
        ?string $captureId,
        ?int $fulfillmentId,
        string $milestone,
        float $amount,
        string $currency,
    ): void {
        if ($captureId === null || $captureId === '') {
            // No provider-verified capture id: nothing to record — the
            // next fresh read (webhook/reconciliation) records the provider
            // trail instead. Never invent ledger evidence.
            return;
        }

        try {
            DB::transaction(function () use ($transaction, $captureId, $fulfillmentId, $milestone, $amount, $currency) {
                $locked = Transaction::query()
                    ->whereKey($transaction->id)
                    ->lockForUpdate()
                    ->first();

                if (!$locked) {
                    return;
                }

                $existing = is_array($locked->gateway_response) ? $locked->gateway_response : [];
                $ledger = is_array($existing['_captures'] ?? null) ? $existing['_captures'] : [];

                foreach ($ledger as $entry) {
                    if (is_array($entry) && is_string($entry['capture_id'] ?? null) && $entry['capture_id'] === $captureId) {
                        return; // already recorded
                    }
                }

                $ledger[] = [
                    'capture_id' => $captureId,
                    'amount' => [
                        'amount' => $amount,
                        'currency' => $currency,
                    ],
                    'source' => 'listener',
                    'milestone' => $milestone,
                    'fulfillment_id' => $fulfillmentId,
                    'recorded_at' => now()->toISOString(),
                ];

                $existing['_captures'] = $ledger;
                $locked->update(['gateway_response' => $existing]);
            });
        } catch (Throwable $e) {
            // Ledger bookkeeping must never mask the capture outcome; the
            // provider capture stands on its own evidence.
            report($e);
        }
    }

    public function failed(FulfillmentProgressed $event, Throwable $e): void
    {
        report($e);

        Log::error('Tamara capture listener failed', [
            'order_id' => $event->orderId,
            'fulfillment_id' => $event->fulfillmentId,
            'milestone' => $event->milestone,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\Coupon\CouponReservationService;
use App\Services\Inventory\InventoryRestoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;

/**
 * Canonical admin gateway refund.
 *
 * Fail-closed: every validation failure throws BEFORE any provider call,
 * and a provider refusal bubbles as a 422 (the controller maps
 * RuntimeException → 422). Partial refunds accumulate in the
 * gateway_response['_refunds'][] ledger; the credit_notes table is
 * invoice-level (requires an Invoice + numbering sequence) and does not fit
 * transaction-level partial-refund idempotency, so it is intentionally not
 * used here.
 *
 * IDEMPOTENCY SCOPE: per-transaction, not global. The replay check scans only
 * THIS transaction's ledger, so the same idempotency key on a different
 * order/transaction is an independent refund (new provider call, own ledger
 * row) — never a replay of the other row. Cross-order key reuse is safe by
 * construction; keys only need to be unique per transaction.
 *
 * Full refunds reuse the paid-cancel side-effect path from
 * OrderService::changeOrderStatus() (committed-inventory restore + coupon
 * reservation release, never promotion decrement, never coupon return).
 * The `cancelled` transition itself is NOT used: it is disallowed from
 * `completed`, and a refunded order stays `completed` with
 * payment_status `payment-refunded`.
 *
 * LOCKING (F-AUDIT-01): one DB transaction, Transaction then Order
 * lockForUpdate — the global ordering shared with callbacks/webhooks/
 * mark-paid. The previous Order-then-Transaction order deadlocked (MySQL
 * 1213, measured) when an admin refund overlapped a duplicate gateway
 * callback on the same order.
 */
class PaymentRefundService
{
    /**
     * Ledger cap: at most this many refund rows are kept per transaction.
     * Overflow prunes the OLDEST entries first. Financially safe: a full
     * refund closes the chain (remaining hits 0, further refunds 422), so in
     * practice the ledger only grows on partials and stays small; the cap is
     * a backstop against unbounded JSON growth, not a normal path.
     */
    private const MAX_LEDGER_ENTRIES = 100;

    public function __construct(
        private PaymentGatewayFactory $gatewayFactory,
        private GatewaySettingsService $gatewaySettings,
        private PaymentCurrencyResolver $currencyResolver,
        private InventoryRestoreService $inventoryRestoreService,
        private CouponReservationService $couponReservationService,
    ) {}

    /**
     * @return array{order_id: int, transaction_id: int, refunded_amount: float, refunded_currency: string, remaining_refundable: float, full_refund: bool, provider_ref: ?string, idempotent_replay: bool}
     *
     * @throws \RuntimeException on every fail-closed validation or provider refusal.
     */
    public function refund(
        Order $order,
        float $amount,
        ?string $reason,
        string $idempotencyKey,
        int $actorId,
    ): array {
        return DB::transaction(function () use ($order, $amount, $reason, $idempotencyKey, $actorId) {
            // F-AUDIT-01: GLOBAL lock order is Transaction -> Order (same as
            // callbacks/webhooks). Locking the txn row first eliminates the
            // MySQL 1213 deadlock measured when an admin refund overlapped a
            // duplicate gateway callback on the same order.
            $txn = Transaction::query()->where('order_id', $order->getKey())
                ->whereIn('status', ['paid', 'partially_refunded'])
                ->latest()
                ->lockForUpdate()
                ->first();

            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedOrder->payment_status === Order::PAYMENT_STATUS_REFUNDED) {
                throw new \RuntimeException(__('message.ERROR.ALREADY_REFUNDED'));
            }

            if (!in_array($lockedOrder->status, [Order::ORDER_STATUS_COMPLETED, Order::ORDER_STATUS_DELIVERED], true)
                || $lockedOrder->payment_status !== Order::PAYMENT_STATUS_SUCCESS) {
                throw new \RuntimeException(__('message.ERROR.WRONG_REFUND'));
            }

            if (!$txn || !$txn->gateway_transaction_id) {
                throw new \RuntimeException(__('message.ERROR.INVALID_PAYMENT_ID'));
            }

            $txnCurrency = strtoupper(trim((string) $txn->currency));
            $orderCurrency = strtoupper(trim((string) $this->currencyResolver->forOrder($lockedOrder)));

            if ($txnCurrency === '') {
                throw new \RuntimeException(
                    __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => $orderCurrency])
                );
            }

            // Authorize against the TRANSACTION's own (original charge)
            // currency, never the current catalog: after a catalog switch the
            // resolver may return a different code for legacy rows, and the
            // already-collected funds are still denominated in $txnCurrency.
            // A drift vs the order snapshot is logged for ops, not fatal.
            if ($txnCurrency !== $orderCurrency) {
                \Illuminate\Support\Facades\Log::warning('Refund currency drift vs order snapshot - authorizing in transaction currency', [
                    'order_id' => $lockedOrder->getKey(),
                    'transaction_id' => $txn->getKey(),
                    'transaction_currency' => $txnCurrency,
                    'order_currency' => $orderCurrency,
                ]);
            }

            $decimals = CurrencyPrecision::decimalsFor($txnCurrency);
            $ledger = $this->readLedger($txn);

            foreach ($ledger as $entry) {
                if (is_array($entry) && ($entry['idempotency_key'] ?? null) === $idempotencyKey) {
                    // Idempotent replay: the original result, no provider call.
                    // Re-read the txn row under lock so the summary reflects
                    // committed ledger state, not the pre-lock snapshot.
                    $replayTxn = Transaction::query()->whereKey($txn->getKey())->lockForUpdate()->firstOrFail();
                    $replayLedger = $this->readLedger($replayTxn);

                    return $this->summaryFor($lockedOrder, $replayTxn, $replayLedger, (float) ($entry['amount'] ?? 0), $txnCurrency, (bool) ($entry['full_refund'] ?? false), $entry['provider_ref'] ?? null, true);
                }
            }

            $requestMinor = CurrencyPrecision::toMinorUnits($amount, $txnCurrency);
            $paidMinor = CurrencyPrecision::toMinorUnits((float) $txn->amount, $txnCurrency);
            $refundedMinor = $this->refundedMinor($ledger, $txnCurrency);

            if ($requestMinor <= 0) {
                throw new \RuntimeException(__('message.ERROR.INVALID_AMOUNT'));
            }

            $remainingMinor = $paidMinor - $refundedMinor;

            if ($requestMinor > $remainingMinor) {
                throw new \RuntimeException(__('message.ERROR.WRONG_REFUND'));
            }

            $gatewayCode = (string) $txn->payment_method;

            if ($this->gatewaySettings->definition($gatewayCode) === null) {
                throw new \RuntimeException(__('message.ERROR.INVALID_GATEWAY'));
            }

            // New outbound provider call: disabled or unconfigured gateways
            // fail closed. (In-flight verify/callback completion is
            // intentionally unaffected — see PaymentGatewayRegistry.)
            if (!$this->gatewaySettings->isEnabled($gatewayCode)) {
                throw new \RuntimeException(__('message.ERROR.PAYMENT_GATEWAY_UNAVAILABLE'));
            }

            try {
                $adapter = $this->gatewayFactory->make($gatewayCode);
            } catch (\App\Exceptions\UnsupportedGatewayException $e) {
                throw new \RuntimeException(__('message.ERROR.INVALID_GATEWAY'));
            }

            if (!$adapter->isConfigured()) {
                throw new \RuntimeException(__('message.ERROR.PAYMENT_GATEWAY_UNAVAILABLE'));
            }

            $sanitizedReason = $reason !== null && trim($reason) !== ''
                ? Str::limit(strip_tags($reason), 500, '')
                : null;

            $result = $adapter->refund($lockedOrder, $amount, $sanitizedReason);

            if (!$result->success) {
                throw new \RuntimeException(
                    Str::limit(strip_tags((string) ($result->errorMessage ?? __('message.ERROR.PAYMENT_FAILED'))), 500, '')
                );
            }

            $resultCurrency = $result->currency !== null ? strtoupper(trim((string) $result->currency)) : $txnCurrency;

            if ($resultCurrency !== $txnCurrency) {
                throw new \RuntimeException(
                    __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => $resultCurrency])
                );
            }

            $isFull = ($remainingMinor - $requestMinor) <= 0;
            $providerRef = $result->gatewayTransactionId;

            $ledger[] = [
                'id' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'amount' => CurrencyPrecision::fromMinorUnits($requestMinor, $txnCurrency),
                'currency' => $txnCurrency,
                'reason' => $sanitizedReason,
                'by' => $actorId,
                'at' => now()->toIso8601String(),
                'provider_ref' => $providerRef,
                'provider_status' => $result->status,
                'full_refund' => $isFull,
            ];
            $ledger = $this->capLedger($ledger);

            $gatewayResponse = is_array($txn->gateway_response) ? $txn->gateway_response : [];
            $gatewayResponse['_refunds'] = $ledger;

            $txn->update([
                'status' => $isFull ? 'refunded' : 'partially_refunded',
                'gateway_response' => $gatewayResponse,
            ]);

            $oldPaymentStatus = $lockedOrder->payment_status;

            if ($isFull) {
                $lockedOrder->update(['payment_status' => Order::PAYMENT_STATUS_REFUNDED]);
                $this->applyFullRefundSideEffects($lockedOrder);
            }

            $lockedOrder->recordStatusChange(
                oldStatus: $lockedOrder->status,
                newStatus: $lockedOrder->status,
                changedBy: $actorId,
                changedByType: 'admin',
                notes: "Refund of {$amount} {$txnCurrency} processed (" . ($isFull ? 'full' : 'partial') . ')' . ($sanitizedReason !== null ? " — Reason: {$sanitizedReason}" : ''),
                metadata: [
                    'action' => 'gateway_refund',
                    'transaction_id' => $txn->id,
                    'gateway' => $gatewayCode,
                    'amount' => CurrencyPrecision::fromMinorUnits($requestMinor, $txnCurrency),
                    'currency' => $txnCurrency,
                    'full_refund' => $isFull,
                    'idempotency_key' => $idempotencyKey,
                    'provider_ref' => $providerRef,
                    'reason' => $sanitizedReason,
                ],
                oldPaymentStatus: $oldPaymentStatus,
                newPaymentStatus: $lockedOrder->payment_status,
            );

            // Re-read the locked row so the summary reflects committed state,
            // not the pre-update snapshot held since the start of the txn.
            $freshTxn = Transaction::query()->whereKey($txn->getKey())->lockForUpdate()->firstOrFail();

            return $this->summaryFor(
                $lockedOrder->fresh() ?? $lockedOrder,
                $freshTxn,
                $this->readLedger($freshTxn),
                CurrencyPrecision::fromMinorUnits($requestMinor, $txnCurrency),
                $txnCurrency,
                $isFull,
                $providerRef,
                false,
            );
        });
    }

    /**
     * Provider-initiated (external) refund, e.g. a PayPal
     * PAYMENT.CAPTURE.REFUNDED / REVERSED webhook.
     *
     * LOCKING CONTRACT: the caller MUST hold an open DB transaction with both
     * $order and $txn already fetched via lockForUpdate() (the webhook does
     * this). This method performs no locking itself — the only row reads are
     * the locked instances passed in.
     *
     * Idempotent by $eventId: a replayed provider event appends nothing and
     * returns the original entry's summary with idempotent_replay=true. A
     * full external refund runs the SAME paid-cancel side effects as a manual
     * full refund (inventory restore + coupon release) via the shared
     * applyFullRefundSideEffects().
     *
     * @return array{order_id: int, transaction_id: int, refunded_amount: float, refunded_currency: string, remaining_refundable: float, full_refund: bool, provider_ref: ?string, idempotent_replay: bool}
     *
     * @throws \RuntimeException on currency divergence (fail-closed).
     */
    public function recordExternalRefund(
        Order $order,
        Transaction $txn,
        string $providerRef,
        float $amount,
        string $currency,
        string $eventId,
        ?int $actorId = null,
    ): array {
        $txnCurrency = strtoupper(trim((string) $txn->currency));
        $eventCurrency = strtoupper(trim($currency));

        if ($txnCurrency === '' || $eventCurrency === '' || $eventCurrency !== $txnCurrency) {
            throw new \RuntimeException(
                __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => $eventCurrency !== '' ? $eventCurrency : $txnCurrency])
            );
        }

        $ledger = $this->readLedger($txn);

        foreach ($ledger as $entry) {
            if (is_array($entry) && ($entry['event_id'] ?? null) === $eventId) {
                return $this->summaryFor($order, $txn, $ledger, (float) ($entry['amount'] ?? 0), $txnCurrency, (bool) ($entry['full_refund'] ?? false), $entry['provider_ref'] ?? null, true);
            }
        }

        $requestMinor = CurrencyPrecision::toMinorUnits($amount, $txnCurrency);
        $paidMinor = CurrencyPrecision::toMinorUnits((float) $txn->amount, $txnCurrency);
        $refundedMinor = $this->refundedMinor($ledger, $txnCurrency);

        if ($requestMinor <= 0 || $requestMinor > ($paidMinor - $refundedMinor)) {
            throw new \RuntimeException(__('message.ERROR.WRONG_REFUND'));
        }

        $isFull = (($paidMinor - $refundedMinor) - $requestMinor) <= 0;

        $ledger[] = [
            'id' => (string) Str::uuid(),
            'event_id' => $eventId,
            'idempotency_key' => 'external:'.$eventId,
            'amount' => CurrencyPrecision::fromMinorUnits($requestMinor, $txnCurrency),
            'currency' => $txnCurrency,
            'reason' => null,
            'by' => $actorId,
            'at' => now()->toIso8601String(),
            'provider_ref' => $providerRef !== '' ? $providerRef : null,
            'provider_status' => 'external_refund',
            'full_refund' => $isFull,
            'external' => true,
        ];
        $ledger = $this->capLedger($ledger);

        $gatewayResponse = is_array($txn->gateway_response) ? $txn->gateway_response : [];
        $gatewayResponse['_refunds'] = $ledger;

        $txn->update([
            'status' => $isFull ? 'refunded' : 'partially_refunded',
            'gateway_response' => $gatewayResponse,
        ]);

        $oldPaymentStatus = $order->payment_status;

        if ($isFull) {
            $order->update(['payment_status' => Order::PAYMENT_STATUS_REFUNDED]);
            $this->applyFullRefundSideEffects($order);
        }

        $order->recordStatusChange(
            oldStatus: $order->status,
            newStatus: $order->status,
            changedBy: $actorId,
            changedByType: $actorId !== null ? 'admin' : 'payment_gateway',
            notes: "External refund of {$amount} {$txnCurrency} recorded (" . ($isFull ? 'full' : 'partial') . ')',
            metadata: [
                'action' => 'external_refund',
                'transaction_id' => $txn->id,
                'gateway' => (string) $txn->payment_method,
                'amount' => CurrencyPrecision::fromMinorUnits($requestMinor, $txnCurrency),
                'currency' => $txnCurrency,
                'full_refund' => $isFull,
                'event_id' => $eventId,
                'provider_ref' => $providerRef !== '' ? $providerRef : null,
            ],
            oldPaymentStatus: $oldPaymentStatus,
            newPaymentStatus: $order->payment_status,
        );

        return $this->summaryFor(
            $order,
            $txn,
            $ledger,
            CurrencyPrecision::fromMinorUnits($requestMinor, $txnCurrency),
            $txnCurrency,
            $isFull,
            $providerRef !== '' ? $providerRef : null,
            false,
        );
    }

    /**
     * F-AUDIT-02: remaining refundable for cross-path approval gating (used by
     * the Marvel refund-request workflow). Lock-free read; the caller
     * serializes decisions with its own claim lock. Null when no refundable
     * transaction exists.
     *
     * @return array{transaction_id: int, currency: string, paid_minor: int, refunded_minor: int, remaining_minor: int}|null
     */
    public function ledgerRemaining(int $orderId): ?array
    {
        $txn = Transaction::query()->where('order_id', $orderId)
            ->whereIn('status', ['paid', 'partially_refunded'])
            ->latest()
            ->first();

        if (!$txn) {
            return null;
        }

        $currency = strtoupper(trim((string) $txn->currency));

        if ($currency === '') {
            return null;
        }

        $paidMinor = CurrencyPrecision::toMinorUnits((float) $txn->amount, $currency);
        $refundedMinor = $this->refundedMinor($this->readLedger($txn), $currency);

        return [
            'transaction_id' => (int) $txn->getKey(),
            'currency' => $currency,
            'paid_minor' => $paidMinor,
            'refunded_minor' => $refundedMinor,
            'remaining_minor' => max(0, $paidMinor - $refundedMinor),
        ];
    }

    /**
     * F-AUDIT-02: ledger-only note for provider refunds executed OUTSIDE this
     * service (Marvel refund-request approvals, which own their approval
     * state, wallet/balance compensation and order updates). Appends to the
     * shared `_refunds` ledger so the admin paid-minus-ledger cap accounts
     * for Marvel-path refunds. Changes NO order/txn states and runs NO side
     * effects. Single txn-row lock (no order lock → no lock cycle).
     * Idempotent by $eventId. Fail-closed on currency divergence.
     */
    public function noteProviderRefund(
        int $orderId,
        float $amount,
        string $currency,
        ?string $providerRef,
        string $eventId,
        ?string $providerStatus,
        ?int $actorId,
        bool $allowOverCap = false,
    ): void {
        DB::transaction(function () use ($orderId, $amount, $currency, $providerRef, $eventId, $providerStatus, $actorId, $allowOverCap) {
            $txn = Transaction::query()->where('order_id', $orderId)
                ->whereIn('status', ['paid', 'partially_refunded'])
                ->latest()
                ->lockForUpdate()
                ->first();

            if (!$txn) {
                throw new \RuntimeException(__('message.ERROR.INVALID_PAYMENT_ID'));
            }

            $txnCurrency = strtoupper(trim((string) $txn->currency));
            $eventCurrency = strtoupper(trim($currency));

            if ($txnCurrency === '' || $eventCurrency === '' || $eventCurrency !== $txnCurrency) {
                throw new \RuntimeException(
                    __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => $eventCurrency !== '' ? $eventCurrency : $txnCurrency])
                );
            }

            $ledger = $this->readLedger($txn);

            foreach ($ledger as $entry) {
                if (is_array($entry) && ($entry['event_id'] ?? null) === $eventId) {
                    return;
                }
            }

            $requestMinor = CurrencyPrecision::toMinorUnits($amount, $txnCurrency);
            $paidMinor = CurrencyPrecision::toMinorUnits((float) $txn->amount, $txnCurrency);
            $refundedMinor = $this->refundedMinor($ledger, $txnCurrency);

            // $allowOverCap (Marvel approval path): the provider has ALREADY
            // moved the money, so the outcome must be recorded even past the
            // cap — flagged for ops instead of thrown (which would hide a
            // real money movement). The admin cap then blocks anything
            // further. Default false: fail-closed everywhere else.
            $overCap = $requestMinor > ($paidMinor - $refundedMinor);

            if ($requestMinor <= 0 || ($overCap && !$allowOverCap)) {
                throw new \RuntimeException(__('message.ERROR.WRONG_REFUND'));
            }

            $isFull = (($paidMinor - $refundedMinor) - $requestMinor) <= 0;

            $ledger[] = [
                'id' => (string) Str::uuid(),
                'event_id' => $eventId,
                'idempotency_key' => 'marvel:'.$eventId,
                'amount' => CurrencyPrecision::fromMinorUnits($requestMinor, $txnCurrency),
                'currency' => $txnCurrency,
                'reason' => null,
                'by' => $actorId,
                'at' => now()->toIso8601String(),
                'provider_ref' => $providerRef !== null && $providerRef !== '' ? $providerRef : null,
                'provider_status' => $providerStatus,
                'full_refund' => $isFull,
                'external' => 'marvel-refund',
                'over_cap' => $overCap,
            ];
            $ledger = $this->capLedger($ledger);

            $gatewayResponse = is_array($txn->gateway_response) ? $txn->gateway_response : [];
            $gatewayResponse['_refunds'] = $ledger;

            $txn->update(['gateway_response' => $gatewayResponse]);
        });
    }

    /**
     * Paid-cancel side-effect path shared by manual and external full
     * refunds (mirrors the changeOrderStatus() cancelled branch for paid
     * orders): committed inventory is restored, the coupon reservation is
     * released (idempotent post-payment no-op), promotion usage is never
     * decremented and consumed coupons never return.
     */
    private function applyFullRefundSideEffects(Order $lockedOrder): void
    {
        $wasCommitted = $lockedOrder->inventory_state === Order::INVENTORY_STATE_COMMITTED;

        if ($wasCommitted) {
            $this->inventoryRestoreService->restore($lockedOrder->fresh() ?? $lockedOrder);
        }
        $this->couponReservationService->release($lockedOrder);
    }

    /**
     * @return array<int, mixed>
     */
    private function readLedger(Transaction $txn): array
    {
        $response = $txn->gateway_response;

        if (!is_array($response)) {
            return [];
        }

        $refunds = $response['_refunds'] ?? [];

        return is_array($refunds) ? array_values($refunds) : [];
    }

    /**
     * @param array<int, mixed> $ledger
     */
    private function refundedMinor(array $ledger, string $currency): int
    {
        $total = 0;

        foreach ($ledger as $entry) {
            if (is_array($entry) && isset($entry['amount']) && is_numeric($entry['amount'])) {
                $total += CurrencyPrecision::toMinorUnits((float) $entry['amount'], $currency);
            }
        }

        return $total;
    }

    /**
     * Enforce the ledger cap, pruning the OLDEST entries first (see
     * MAX_LEDGER_ENTRIES for the financial-safety rationale).
     *
     * @param array<int, mixed> $ledger
     * @return array<int, mixed>
     */
    private function capLedger(array $ledger): array
    {
        $ledger = array_values($ledger);

        while (count($ledger) > self::MAX_LEDGER_ENTRIES) {
            array_shift($ledger);
        }

        return $ledger;
    }

    /**
     * NOTE: $txn MUST be the locked row (or a re-read under lockForUpdate
     * inside the service transaction). Passing a stale pre-lock snapshot
     * here would compute remaining_refundable from outdated ledger state.
     *
     * @param array<int, mixed> $ledger
     * @return array{order_id: int, transaction_id: int, refunded_amount: float, refunded_currency: string, remaining_refundable: float, full_refund: bool, provider_ref: ?string, idempotent_replay: bool}
     */
    private function summaryFor(
        Order $order,
        Transaction $txn,
        array $ledger,
        float $refundedAmount,
        string $currency,
        bool $isFull,
        ?string $providerRef,
        bool $replay,
    ): array {
        $paidMinor = CurrencyPrecision::toMinorUnits((float) $txn->amount, $currency);

        return [
            'order_id' => (int) $order->getKey(),
            'transaction_id' => (int) $txn->getKey(),
            'refunded_amount' => $refundedAmount,
            'refunded_currency' => $currency,
            'remaining_refundable' => CurrencyPrecision::fromMinorUnits(max(0, $paidMinor - $this->refundedMinor($ledger, $currency)), $currency),
            'full_refund' => $isFull,
            'provider_ref' => $providerRef,
            'idempotent_replay' => $replay,
        ];
    }
}

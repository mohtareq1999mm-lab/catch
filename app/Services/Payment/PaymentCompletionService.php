<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\GatewayResult;
use App\Exceptions\PaymentMismatchException;
use App\Models\PaymentReconciliationResult;
use App\Services\General\OrderService;
use App\Services\Inventory\OrderReservationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;

/**
 * Canonical online-payment completion.
 *
 * Single owner of the "gateway says paid → order completed" transition for
 * every callback entry point (success callback, error callback, webhooks).
 *
 * LOCKING CONTRACT: the caller MUST hold an open DB transaction with both
 * $txn and $order already fetched via lockForUpdate(). This method performs
 * no locking itself; every check below is valid only because the rows are
 * already exclusively held. Coupon-policy failures bubble as
 * CouponConsumptionException so the CALLER's transaction rolls back.
 *
 * Step order mirrors the legacy callback implementation exactly:
 * idempotency token (primary) → order-pending (secondary) → amount/currency
 * fail-closed checks → canonical commit sequence.
 */
class PaymentCompletionService
{
    public function __construct(
        private PaymentCurrencyResolver $currencyResolver,
        private OrderReservationService $orderReservationService,
        private OrderService $orderService,
    ) {}

    /**
     * @param array{test_bypass?: bool} $opts
     *
     * @throws PaymentMismatchException on amount/currency/provider-ref divergence.
     * @throws \App\Exceptions\CouponConsumptionException when coupon policy
     *   refuses completion (caller transaction rolls back; order untouched).
     */
    public function completeLocked(
        Transaction $txn,
        ?Order $order,
        GatewayResult $result,
        array $opts = [],
    ): PaymentCompletionOutcome {
        // GAP-C001: token-based idempotency (PRIMARY defense). A transaction
        // that already carries a token has been processed; never touch it.
        if ($txn->idempotency_key !== null) {
            Log::info('Payment callback idempotent return - already processed', [
                'transaction_id' => $txn->id,
                'idempotency_key' => $txn->idempotency_key,
            ]);

            return PaymentCompletionOutcome::IdempotentReplay;
        }

        // Stamp the token before any business logic so concurrent holders of
        // the same row serialize on the re-check above. This is idempotency
        // bookkeeping, not completion: non-completing exits below keep the
        // token exactly as the legacy callbacks did.
        $idempotencyToken = Str::uuid()->toString();
        $txn->update(['idempotency_key' => $idempotencyToken]);

        if (!$order) {
            return PaymentCompletionOutcome::UnknownOrder;
        }

        // Status-based check (SECONDARY defense).
        if ($order->status !== 'pending') {
            // D-06: provider reports paid for a non-pending order on a
            // DIFFERENT row than the completing one → reconcile-hold. Record
            // for ops; never perform a second completion, never touch
            // order/txn completion state.
            if ($result->success && $this->isDuplicatePayment($order, $txn)) {
                $this->recordDuplicateHold($order, $txn, $result);

                return PaymentCompletionOutcome::DuplicateHold;
            }

            Log::info('Payment callback status-based return - order not pending', [
                'transaction_id' => $txn->id,
                'order_id' => $order->id,
                'order_status' => $order->status,
                'idempotency_key' => $idempotencyToken,
            ]);

            return PaymentCompletionOutcome::OrderNotPending;
        }

        $this->assertNoMismatch($order, $txn, $result, $opts);

        $this->commitLocked($txn, $order, $result);

        return PaymentCompletionOutcome::Processed;
    }

    /**
     * D-06 duplicate: the order already has a paid (completing) transaction
     * row that is NOT the row currently being completed.
     */
    private function isDuplicatePayment(Order $order, Transaction $txn): bool
    {
        $completing = $order->transactions()
            ->where('status', 'paid')
            ->latest()
            ->first();

        return $completing !== null && (int) $completing->id !== (int) $txn->id;
    }

    private function recordDuplicateHold(Order $order, Transaction $txn, GatewayResult $result): void
    {
        $completing = $order->transactions()
            ->where('status', 'paid')
            ->latest()
            ->first();

        PaymentReconciliationResult::create([
            'transaction_id' => $txn->id,
            'order_id' => $order->id,
            'gateway' => $txn->payment_method ?? 'unknown',
            'mismatch_type' => 'duplicate_payment',
            'expected_value' => 'provider_ref=' . ($completing?->gateway_transaction_id ?? $completing?->invoice_id ?? 'unknown')
                . ' amount=' . ($completing?->amount ?? 'unknown'),
            'actual_value' => 'provider_ref=' . ($result->gatewayTransactionId ?? $txn->gateway_transaction_id ?? 'unknown')
                . ' amount=' . ($result->amount ?? $txn->amount ?? 'unknown'),
            'notes' => 'D-06 duplicate-hold: provider reports paid for a non-pending order on a second transaction row. Held for manual reconciliation; no second completion performed.',
        ]);

        Log::warning('Duplicate provider payment held for reconciliation', [
            'order_id' => $order->id,
            'order_status' => $order->status,
            'completing_transaction_id' => $completing?->id,
            'duplicate_transaction_id' => $txn->id,
            'provider_ref' => $result->gatewayTransactionId,
        ]);
    }

    /**
     * Fail-closed amount (x1000, 3-decimal safe) + currency + provider-ref
     * checks. Any divergence throws; the caller marks the transaction failed.
     *
     * NOTE on the fixed x1000 scale: both sides multiply by 1000, so the
     * comparison is exact for every currency with ≤3 fractional digits (2dp
     * amounts compare as N*1000 exactly; 3dp KWD/... compare natively). No
     * per-currency exponent is needed here precisely because the factor is
     * applied symmetrically — do NOT "fix" one side to x100.
     */
    private function assertNoMismatch(Order $order, Transaction $txn, GatewayResult $result, array $opts): void
    {
        $testBypass = (bool) ($opts['test_bypass'] ?? false);

        $expectedCents = (int) round((float) $order->total_price * 1000);
        $receivedCents = $result->amount !== null ? (int) round((float) $result->amount * 1000) : null;
        $receivedCurrency = $result->currency !== null ? strtoupper(trim((string) $result->currency)) : null;
        $expectedCurrency = strtoupper(trim((string) $this->currencyResolver->forOrder($order)));

        if ($receivedCents === null) {
            Log::warning('Payment amount missing - blocking order', [
                'order_id' => $order->id,
                'expected_cents' => $expectedCents,
                'received' => $result->amount,
            ]);

            throw new PaymentMismatchException(
                PaymentMismatchException::REASON_AMOUNT_MISSING,
                '',
                ['order_id' => $order->id, 'expected_cents' => $expectedCents],
            );
        }

        if ($receivedCents !== $expectedCents) {
            if ($testBypass) {
                Log::info('Payment amount mismatch ignored (test gateway bypass explicitly enabled)', [
                    'order_id' => $order->id,
                    'expected_cents' => $expectedCents,
                    'received_cents' => $receivedCents,
                    'expected' => (float) $order->total_price,
                    'received' => $result->amount,
                ]);
            } else {
                Log::warning('Payment amount mismatch - blocking order', [
                    'order_id' => $order->id,
                    'expected_cents' => $expectedCents,
                    'received_cents' => $receivedCents,
                    'currency' => $result->currency,
                ]);

                throw new PaymentMismatchException(
                    PaymentMismatchException::REASON_AMOUNT,
                    '',
                    ['order_id' => $order->id, 'expected_cents' => $expectedCents, 'received_cents' => $receivedCents],
                );
            }
        }

        if ($receivedCurrency === null) {
            Log::warning('Payment currency missing - blocking order', [
                'order_id' => $order->id,
                'expected' => $expectedCurrency,
                'received' => $result->currency,
            ]);

            throw new PaymentMismatchException(
                PaymentMismatchException::REASON_CURRENCY_MISSING,
                '',
                ['order_id' => $order->id, 'expected_currency' => $expectedCurrency],
            );
        }

        if ($receivedCurrency !== $expectedCurrency) {
            if ($testBypass) {
                Log::info('Payment currency mismatch ignored (test gateway bypass explicitly enabled)', [
                    'order_id' => $order->id,
                    'expected' => $expectedCurrency,
                    'received' => $receivedCurrency,
                ]);
            } else {
                Log::warning('Payment currency mismatch - blocking order', [
                    'order_id' => $order->id,
                    'expected' => $expectedCurrency,
                    'received' => $receivedCurrency,
                ]);

                throw new PaymentMismatchException(
                    PaymentMismatchException::REASON_CURRENCY,
                    '',
                    ['order_id' => $order->id, 'expected_currency' => $expectedCurrency, 'received_currency' => $receivedCurrency],
                );
            }
        }

        // The provider reference must belong to this transaction row. A paid
        // verification for somebody else's invoice must never complete us.
        $verifiedRef = $result->gatewayTransactionId !== null ? trim((string) $result->gatewayTransactionId) : '';
        if ($verifiedRef !== '') {
            $localRefs = array_filter([
                $txn->gateway_transaction_id !== null ? trim((string) $txn->gateway_transaction_id) : '',
                $txn->invoice_id !== null ? trim((string) $txn->invoice_id) : '',
            ]);

            if (!in_array($verifiedRef, $localRefs, true)) {
                Log::warning('Payment provider-ref mismatch - blocking order', [
                    'order_id' => $order->id,
                    'transaction_id' => $txn->id,
                    'verified_ref' => $verifiedRef,
                ]);

                throw new PaymentMismatchException(
                    PaymentMismatchException::REASON_PROVIDER_REF,
                    '',
                    ['order_id' => $order->id, 'transaction_id' => $txn->id, 'verified_ref' => $verifiedRef],
                );
            }
        }
    }

    /**
     * The canonical commit sequence, byte-for-byte the legacy callback
     * behavior: txn → paid, order payment markers, reservation commit,
     * promotion finalization, then the completed transition (which owns
     * coupon usage and throws CouponConsumptionException on refusal).
     */
    private function commitLocked(Transaction $txn, Order $order, GatewayResult $result): void
    {
        $existingResponse = is_array($txn->gateway_response) ? $txn->gateway_response : [];
        $callbackType = $existingResponse['_callback_type'] ?? null;
        $mergedResponse = is_array($result->rawResponse) ? $result->rawResponse : [];
        if ($callbackType) {
            $mergedResponse['_callback_type'] = $callbackType;
        }
        $sanitizedPaid = \Illuminate\Support\Str::limit(strip_tags((string) ($result->errorMessage ?? '')), 500, '');
        $txn->update([
            'status' => 'paid',
            'gateway_response' => $mergedResponse,
            'error_message' => $sanitizedPaid ?: null,
            'paid_at' => now(),
        ]);

        $orderUpdateData = [];
        if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'payment_status')) {
            $orderUpdateData['payment_status'] = \Marvel\Enums\PaymentStatus::SUCCESS;
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'paid_at')) {
            $orderUpdateData['paid_at'] = now();
        }
        if (!empty($orderUpdateData)) {
            $order->update($orderUpdateData);
        }

        // Commit THIS order's reservation. The order snapshot is the only
        // inventory source — the current cart is never read here.
        $this->orderReservationService->commit($order);

        $this->orderService->finalizePromotionUsageAfterPayment($order);

        // emitPaymentSuccess = false: the callback owns the PaymentSucceeded
        // dispatch and fires it once after the transaction commits.
        // Authority exemption: payment authority was established by provider
        // verification before this service was entered.
        $this->orderService->changeOrderStatus($txn->invoice_id, 'completed', null, false, null, null, false);
    }
}

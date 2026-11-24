<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\CollectibleCodAmount;
use App\Exceptions\CodAmountEvidenceException;
use App\Models\Currency;
use App\Services\Refund\RefundService;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Refund;
use Marvel\Database\Models\Transaction;

/**
 * #150/#168: shared read-only authority for collectible COD money.
 *
 * #168 eligibility gate: COD collectible is computed only when
 * payment_method is exactly 'cod'. Ineligible methods (online,
 * pay_at_cashier, legacy null/unknown) snapshot zero and never reach the
 * COD formula. The gate runs before any amount/currency/precision/evidence
 * calculation so ineligible rows cannot fail on missing evidence.
 *
 * Evidence is restricted to the Marvel order (grand total and currency),
 * captured Marvel transactions, and canonical approved refunds. Legacy
 * gateway_response['_refunds'] rows are deliberately NOT represented: the
 * canonical refunds table is the single refund authority, so a refund cannot
 * be counted twice by appearing in both places.
 *
 * No client amount, fee rule, tax rule, currency conversion, fallback paid
 * total, or default currency is accepted. Ambiguous evidence fails closed.
 */
final class OrderPaidAmountService
{
    /** Paid statuses that represent money that was actually captured. */
    private const CAPTURED_STATUSES = ['paid', 'partially_refunded', 'refunded'];

    /** A present capture_state must prove success; unknowns are not money. */
    private const SUCCESSFUL_CAPTURE_STATES = ['succeeded'];

    /** Order payment states that claim captured money must prove it with a row. */
    private const PAID_CLAIM_STATUSES = [
        Order::PAYMENT_STATUS_SUCCESS,
        Order::PAYMENT_STATUS_REFUNDED,
    ];

    /** #124/#168: exact COD allowlist. */
    private const COD_PAYMENT_METHOD = 'cod';

    public function forOrder(Order|int $order): CollectibleCodAmount
    {
        $order = $this->resolveOrder($order);

        // #124/#168: eligibility is an exact-match gate and runs before any
        // currency, precision, evidence, or collectible-formula work.
        if (! $this->isCodPaymentMethod($order)) {
            return $this->ineligibleSnapshot($order);
        }

        $currencyCode = $this->currencyCode($order);
        $decimalPlaces = $this->decimalPlaces($currencyCode);

        $orderTotalMinor = $this->toMinorUnits($this->rawAmount($order, 'total_price'), $decimalPlaces);
        $capturedMinor = $this->capturedMinor($order, $currencyCode, $decimalPlaces);
        $refundedMinor = $this->refundedMinor($order, $currencyCode, $decimalPlaces);

        if ($refundedMinor > $capturedMinor) {
            throw CodAmountEvidenceException::inconsistentEvidence();
        }

        return new CollectibleCodAmount(
            orderId: (int) $order->getKey(),
            currencyCode: $currencyCode,
            decimalPlaces: $decimalPlaces,
            orderTotalMinor: $orderTotalMinor,
            capturedMinor: $capturedMinor,
            refundedMinor: $refundedMinor,
            collectibleMinor: $orderTotalMinor - $capturedMinor + $refundedMinor,
        );
    }

    private function isCodEligible(Order $order): bool
    {
        $method = strtolower(trim((string) ($order->payment_method ?? '')));

        return $method === self::COD_PAYMENT_METHOD;
    }

    private function ineligibleSnapshot(Order $order): CollectibleCodAmount
    {
        $currencyCode = strtoupper(trim((string) ($order->currency_code ?? '')));
        $decimalPlaces = 0;
        if (preg_match('/^[A-Z]{3}$/', $currencyCode)) {
            $currency = Currency::query()->where('code', $currencyCode)->first();
            if ($currency !== null && $currency->decimal_places !== null) {
                $candidate = (int) $currency->decimal_places;
                if ($candidate >= 0 && $candidate <= 4) {
                    $decimalPlaces = $candidate;
                }
            }
        } else {
            $currencyCode = '';
        }

        return new CollectibleCodAmount(
            orderId: (int) $order->getKey(),
            currencyCode: $currencyCode,
            decimalPlaces: $decimalPlaces,
            orderTotalMinor: 0,
            capturedMinor: 0,
            refundedMinor: 0,
            collectibleMinor: 0,
        );
    }

    private function resolveOrder(Order|int $order): Order
    {
        if ($order instanceof Order) {
            if (!$order->exists) {
                throw CodAmountEvidenceException::missingOrder();
            }

            return $order;
        }

        if ($order <= 0) {
            throw CodAmountEvidenceException::missingOrder();
        }

        $resolved = Order::query()->find($order);
        if ($resolved === null) {
            throw CodAmountEvidenceException::missingOrder();
        }

        return $resolved;
    }

    private function currencyCode(Order $order): string
    {
        $code = strtoupper(trim((string) ($order->currency_code ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            throw CodAmountEvidenceException::missingCurrency();
        }

        return $code;
    }

    private function isCodPaymentMethod(Order $order): bool
    {
        return is_string($order->payment_method) && $order->payment_method === self::COD_PAYMENT_METHOD;
    }

    private function decimalPlaces(string $currencyCode): int
    {
        $currency = Currency::query()->where('code', $currencyCode)->first();
        if ($currency === null || $currency->decimal_places === null) {
            throw CodAmountEvidenceException::missingPrecision();
        }

        $decimalPlaces = (int) $currency->decimal_places;
        if ($decimalPlaces < 0 || $decimalPlaces > 4) {
            throw CodAmountEvidenceException::invalidPrecision();
        }

        return $decimalPlaces;
    }

    private function capturedMinor(Order $order, string $currencyCode, int $decimalPlaces): int
    {
        $captured = $this->qualifyingCapturedTransactions($order, $currencyCode, $decimalPlaces);

        if ($captured === [] && in_array((string) $order->payment_status, self::PAID_CLAIM_STATUSES, true)) {
            throw CodAmountEvidenceException::missingPaidEvidence();
        }

        $total = 0;
        foreach ($captured as $row) {
            $total += $row['minor'];
        }

        return $total;
    }

    /**
     * Qualifying captured transactions, deduped by capture identity so duplicate
     * rows do not inflate collectible COD. Distinct legitimate captures (different
     * gateway/idempotency identity) are summed; this preserves partial-capture
     * correctness while preventing duplicate-row inflation.
     *
     * Identity key: gateway_transaction_id, then idempotency_key. Captures
     * lacking both stable identities cannot be proven distinct; any ambiguous
     * no-identity duplicate evidence fails closed (AC2/AC4).
     *
     * @return array<string, array{id: int, minor: int}>
     */
    private function qualifyingCapturedTransactions(Order $order, string $currencyCode, int $decimalPlaces): array
    {
        $captured = [];
        $ambiguousNoIdentity = 0;

        Transaction::query()
            ->where('order_id', $order->getKey())
            ->whereIn('status', self::CAPTURED_STATUSES)
            ->orderBy('id')
            ->each(function (Transaction $transaction) use ($order, $currencyCode, $decimalPlaces, &$captured, &$ambiguousNoIdentity): void {
                $state = strtolower(trim((string) ($transaction->capture_state ?? '')));
                if ($state !== '' && !in_array($state, self::SUCCESSFUL_CAPTURE_STATES, true)) {
                    return;
                }

                $this->assertTransactionEvidenceBelongsToOrder($transaction, $order, $currencyCode);

                $minor = $this->toMinorUnits(
                    $this->rawAmount($transaction, 'amount'),
                    $decimalPlaces
                );

                $identity = $this->captureIdentity($transaction);
                if ($identity === null) {
                    $ambiguousNoIdentity++;
                    if ($ambiguousNoIdentity > 1) {
                        throw CodAmountEvidenceException::inconsistentEvidence();
                    }

                    $identity = 'transaction:'.(int) $transaction->getKey();
                }

                if (isset($captured[$identity])) {
                    if ($captured[$identity]['minor'] !== $minor) {
                        throw CodAmountEvidenceException::inconsistentEvidence();
                    }

                    // Duplicate evidence row for same capture identity: ignore, do not inflate.
                    return;
                }

                $captured[$identity] = [
                    'id' => (int) $transaction->getKey(),
                    'minor' => $minor,
                ];
            });

        return $captured;
    }

    private function captureIdentity(Transaction $transaction): ?string
    {
        $gatewayId = trim((string) ($transaction->gateway_transaction_id ?? ''));
        if ($gatewayId !== '') {
            return 'gateway:'.$gatewayId;
        }

        $idempotencyKey = trim((string) ($transaction->idempotency_key ?? ''));
        if ($idempotencyKey !== '') {
            return 'idempotency:'.$idempotencyKey;
        }

        return null;
    }

    private function assertTransactionEvidenceBelongsToOrder(Transaction $transaction, Order $order, string $currencyCode): void
    {
        $transactionOrderId = (int) ($transaction->order_id ?? 0);
        if ($transactionOrderId !== (int) $order->getKey()) {
            throw CodAmountEvidenceException::inconsistentEvidence();
        }

        $transactionCurrency = strtoupper(trim((string) ($transaction->currency ?? '')));
        if ($transactionCurrency !== $currencyCode) {
            throw CodAmountEvidenceException::mixedCurrency();
        }
    }

    /**
     * Canonical approved refunds are the single refund authority. Each row is
     * counted once by its primary-key identity, never by amount, and legacy
     * gateway ledger entries are ignored rather than added a second time.
     */
    private function refundedMinor(Order $order, string $currencyCode, int $decimalPlaces): int
    {
        $refundedMinor = 0;

        Refund::query()
            ->where('order_id', $order->getKey())
            ->where('status', RefundService::STATUS_APPROVED)
            ->orderBy('id')
            ->each(function (Refund $refund) use ($currencyCode, $decimalPlaces, &$refundedMinor): void {
                $refundCurrency = strtoupper(trim((string) (
                    $refund->getRawOriginal('currency') ?? $refund->currency ?? ''
                )));
                if ($refundCurrency !== $currencyCode) {
                    throw CodAmountEvidenceException::mixedCurrency();
                }

                $refundedMinor += $this->toMinorUnits(
                    $this->rawAmount($refund, 'amount'),
                    $decimalPlaces
                );
            });

        return $refundedMinor;
    }

    private function rawAmount(Order|Refund|Transaction $model, string $column): mixed
    {
        $raw = $model->getRawOriginal($column);

        return $raw ?? $model->{$column};
    }

    /**
     * Exact decimal string -> integer minor units. Ints, finite floats and
     * strings are normalised without rounding; trailing fractional zeros are
     * canonicalised because MySQL stores money with fixed scale, but a
     * non-zero fraction beyond the currency's precision is never rounded.
     */
    private function toMinorUnits(mixed $amount, int $decimalPlaces): int
    {
        if (is_float($amount) && !is_finite($amount)) {
            throw CodAmountEvidenceException::invalidAmount();
        }

        if (!is_int($amount) && !is_float($amount) && !is_string($amount)) {
            throw CodAmountEvidenceException::invalidAmount();
        }

        $raw = $this->exactDecimalString($amount);
        if (!preg_match('/^(\d+)(?:\.(\d+))?$/', $raw, $matches)) {
            throw CodAmountEvidenceException::invalidAmount();
        }

        $fraction = rtrim($matches[2] ?? '', '0');
        if (strlen($fraction) > $decimalPlaces) {
            throw CodAmountEvidenceException::invalidAmount();
        }

        $digits = $decimalPlaces === 0
            ? $matches[1]
            : $matches[1].str_pad($fraction, $decimalPlaces, '0');
        $digits = ltrim($digits, '0') ?: '0';
        $max = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw CodAmountEvidenceException::invalidAmount();
        }

        return (int) $digits;
    }

    private function exactDecimalString(int|float|string $amount): string
    {
        if (is_string($amount)) {
            return trim($amount);
        }
        if (is_int($amount)) {
            return (string) $amount;
        }

        try {
            return (string) json_encode($amount, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw CodAmountEvidenceException::invalidAmount();
        }
    }
}

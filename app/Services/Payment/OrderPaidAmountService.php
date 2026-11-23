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
 * #150: shared read-only authority for collectible COD money.
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

    public function forOrder(Order|int $order): CollectibleCodAmount
    {
        $order = $this->resolveOrder($order);
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
        $capturedMinor = 0;
        $capturedRows = 0;

        Transaction::query()
            ->where('order_id', $order->getKey())
            ->whereIn('status', self::CAPTURED_STATUSES)
            ->orderBy('id')
            ->each(function (Transaction $transaction) use (
                $currencyCode,
                $decimalPlaces,
                &$capturedMinor,
                &$capturedRows
            ): void {
                $state = strtolower(trim((string) ($transaction->capture_state ?? '')));
                if ($state !== '' && !in_array($state, self::SUCCESSFUL_CAPTURE_STATES, true)) {
                    return;
                }

                $capturedRows++;

                if (strtoupper(trim((string) ($transaction->currency ?? ''))) !== $currencyCode) {
                    throw CodAmountEvidenceException::mixedCurrency();
                }

                $capturedMinor += $this->toMinorUnits(
                    $this->rawAmount($transaction, 'amount'),
                    $decimalPlaces
                );
            });

        if ($capturedRows === 0 && in_array((string) $order->payment_status, self::PAID_CLAIM_STATUSES, true)) {
            throw CodAmountEvidenceException::missingPaidEvidence();
        }

        return $capturedMinor;
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

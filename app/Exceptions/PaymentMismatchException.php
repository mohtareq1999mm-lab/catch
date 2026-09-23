<?php

namespace App\Exceptions;

/**
 * Thrown when a gateway-verified payment cannot be applied to the locked
 * order/transaction because local expectations do not match the provider
 * result (amount, currency, or provider reference).
 *
 * Fail-closed contract: the caller MUST catch this inside its own
 * DB transaction, mark the transaction failed visibly, and return a
 * failure response. The order must never complete on a mismatch.
 */
class PaymentMismatchException extends \RuntimeException
{
    public const REASON_AMOUNT = 'amount_mismatch';
    public const REASON_CURRENCY = 'currency_mismatch';
    public const REASON_AMOUNT_MISSING = 'amount_missing';
    public const REASON_CURRENCY_MISSING = 'currency_missing';
    public const REASON_PROVIDER_REF = 'provider_ref_mismatch';

    public function __construct(
        public readonly string $reason,
        string $message = '',
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : "Payment mismatch: {$reason}", 0, $previous);
    }
}

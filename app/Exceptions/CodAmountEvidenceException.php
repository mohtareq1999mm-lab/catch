<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Fail-closed guard for COD amount evidence.
 *
 * No fallback currency, precision, paid total, or refund identity is ever
 * invented: if the authoritative evidence cannot be read unambiguously the
 * amount is not computed.
 */
final class CodAmountEvidenceException extends RuntimeException
{
    public static function missingOrder(): self
    {
        return new self(__('cod_amount.missing_order'));
    }

    public static function missingCurrency(): self
    {
        return new self(__('cod_amount.missing_currency'));
    }

    public static function missingPrecision(): self
    {
        return new self(__('cod_amount.missing_precision'));
    }

    public static function invalidPrecision(): self
    {
        return new self(__('cod_amount.invalid_precision'));
    }

    public static function missingPaidEvidence(): self
    {
        return new self(__('cod_amount.missing_paid_evidence'));
    }

    public static function invalidAmount(): self
    {
        return new self(__('cod_amount.invalid_amount'));
    }

    public static function mixedCurrency(): self
    {
        return new self(__('cod_amount.mixed_currency'));
    }

    public static function inconsistentEvidence(): self
    {
        return new self(__('cod_amount.inconsistent_evidence'));
    }
}

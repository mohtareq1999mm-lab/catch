<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Fail-closed guard for settlement money.
 *
 * Thrown whenever the order currency, its decimal_places metadata, or an
 * amount's fraction cannot be resolved without guessing precision. The same
 * message is used for mixed currencies because implicit conversion is never
 * allowed.
 */
class CodSettlementPrecisionException extends InvalidArgumentException
{
    public static function forCurrency(): self
    {
        return new self(__('cod_settlement.precision_unavailable'));
    }

    public static function forAmount(): self
    {
        return new self(__('cod_settlement.precision_unavailable'));
    }

    public static function forMixedCurrency(): self
    {
        return new self(__('cod_settlement.currency_conflict'));
    }
}

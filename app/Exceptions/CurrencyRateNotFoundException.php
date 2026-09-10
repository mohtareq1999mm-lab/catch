<?php

namespace App\Exceptions;

use Exception;

class CurrencyRateNotFoundException extends Exception
{
    public static function forCurrency(string $currencyCode, string $date): self
    {
        return new self(
            sprintf('No exchange rate found for currency [%s] on or before [%s].', $currencyCode, $date)
        );
    }
}

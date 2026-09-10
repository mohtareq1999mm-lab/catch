<?php

namespace App\Exceptions;

use Exception;

class CurrencyInactiveException extends Exception
{
    public static function forCurrency(string $currencyCode): self
    {
        return new self(
            sprintf('Inactive currency [%s] cannot be set as the base currency.', $currencyCode)
        );
    }
}

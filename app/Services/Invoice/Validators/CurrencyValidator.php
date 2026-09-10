<?php

namespace App\Services\Invoice\Validators;

use App\Contracts\Services\Invoice\SnapshotValidatorInterface;
use App\Exceptions\CurrencyMismatchException;

class CurrencyValidator implements SnapshotValidatorInterface
{
    private const ALLOWED_CURRENCIES = ['EGP', 'USD', 'EUR', 'GBP', 'SAR', 'AED'];

    public function validate(array $snapshot): void
    {
        $currency = $snapshot['pricing_breakdown']['currency'] ?? null;

        if ($currency === null) {
            throw new CurrencyMismatchException('Currency is missing from pricing_breakdown');
        }

        if (!in_array($currency, self::ALLOWED_CURRENCIES, true)) {
            throw new CurrencyMismatchException("Unsupported currency: {$currency}");
        }
    }
}

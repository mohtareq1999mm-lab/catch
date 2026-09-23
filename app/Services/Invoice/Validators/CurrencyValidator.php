<?php

namespace App\Services\Invoice\Validators;

use App\Contracts\Services\Invoice\SnapshotValidatorInterface;
use App\Exceptions\CurrencyMismatchException;

class CurrencyValidator implements SnapshotValidatorInterface
{
    /**
     * Fallback when gateway config is unavailable (tests, early boot).
     * Must stay a subset of checkout-supported currencies.
     */
    private const FALLBACK_CURRENCIES = ['EGP', 'USD', 'EUR', 'GBP', 'SAR', 'AED', 'KWD', 'BHD', 'QAR', 'OMR'];

    public function validate(array $snapshot): void
    {
        $currency = $snapshot['pricing_breakdown']['currency'] ?? null;

        if ($currency === null) {
            throw new CurrencyMismatchException('Currency is missing from pricing_breakdown');
        }

        // Single source of truth: checkout-supported gateway currencies.
        // A second hardcoded list here silently desyncs (e.g. KWD completions
        // failing invoice generation while checkout accepts KWD).
        $configured = config('payment.gateways.myfatoorah.supported_currencies', []);
        $allowed = is_array($configured) && $configured !== []
            ? array_map('strtoupper', array_map('strval', $configured))
            : self::FALLBACK_CURRENCIES;

        if (!in_array(strtoupper((string) $currency), $allowed, true)) {
            throw new CurrencyMismatchException("Unsupported currency: {$currency}");
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Payment;

/**
 * Single authority for currency decimal precision on the payment path.
 *
 * ISO 4217 exponent 3 currencies (BHD, JOD, KWD, OMR, TND) carry 3 fractional
 * digits; everything else on this path carries 2. Snapshots, gateway payloads,
 * and refund math must all agree on the exponent or millis-style comparisons
 * silently drop the third decimal (e.g. 13.255 KWD snapshotted as 13.25).
 *
 * NOTE: Stripe's zero-decimal list (JPY, KRW, ...) is a Stripe-specific
 * override and intentionally stays in StripeGateway — this helper only models
 * the 3-vs-2 split used everywhere else.
 */
final class CurrencyPrecision
{
    /**
     * ISO 4217 exponent-3 currencies.
     */
    private const THREE_DECIMAL_CURRENCIES = [
        'BHD', 'JOD', 'KWD', 'OMR', 'TND',
    ];

    public static function decimalsFor(string $code): int
    {
        return in_array(strtoupper(trim($code)), self::THREE_DECIMAL_CURRENCIES, true) ? 3 : 2;
    }

    public static function roundForCurrency(float $amount, string $code): float
    {
        return round($amount, self::decimalsFor($code));
    }

    /**
     * Major units → minor units (×10^decimals). Zero-decimal handling lives
     * in StripeGateway::toMinorUnits and must NOT be added here.
     */
    public static function toMinorUnits(float $amount, string $code): int
    {
        return (int) round($amount * (10 ** self::decimalsFor($code)));
    }

    public static function fromMinorUnits(int $minorUnits, string $code): float
    {
        return $minorUnits / (10 ** self::decimalsFor($code));
    }

    /**
     * Minor-unit formatting for decimal-string APIs (PayPal value fields).
     * E.g. 13.255 KWD → '13.255', 10.5 USD → '10.50'.
     */
    public static function formatForGateway(float $amount, string $code): string
    {
        return number_format(self::roundForCurrency($amount, $code), self::decimalsFor($code), '.', '');
    }
}

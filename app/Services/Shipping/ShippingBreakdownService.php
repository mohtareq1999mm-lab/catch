<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Models\Currency;
use App\Services\Payment\CurrencyPrecision;
use InvalidArgumentException;

class ShippingBreakdownService
{
    public const VERSION = 1;

    /**
     * @param array<string, mixed> $input
     * @param bool $allowCodFee When false, any non-null cod_fee is rejected (COD fee disabled).
     * @return array<string, mixed>
     */
    public function build(array $input, bool $allowCodFee = false): array
    {
        $currencyCode = strtoupper(trim((string) ($input['currency'] ?? '')));
        if ($currencyCode === '') {
            throw new InvalidArgumentException('shipping_breakdown.currency is required.');
        }

        $precision = $this->resolvePrecision($currencyCode, $input['precision'] ?? null);

        $baseShipping = $this->money($input['base_shipping'] ?? null, 'base_shipping', $precision);
        $thresholdWaiver = $this->money($input['threshold_waiver'] ?? null, 'threshold_waiver', $precision);
        $couponWaiver = $this->money($input['coupon_waiver'] ?? null, 'coupon_waiver', $precision);
        $fastShippingFee = $this->money($input['fast_shipping_fee'] ?? null, 'fast_shipping_fee', $precision);
        $codFee = $this->nullableMoney($input['cod_fee'] ?? null, 'cod_fee', $precision);

        if ($codFee !== null && !$allowCodFee) {
            throw new InvalidArgumentException('shipping_breakdown.cod_fee is disabled.');
        }

        $netShipping = $this->round($baseShipping - $thresholdWaiver - $couponWaiver, $precision);
        $total = $this->round($netShipping + ($codFee ?? 0.0) + $fastShippingFee, $precision);

        $this->assertProvidedMatches($input, 'net_shipping', $netShipping, $precision);
        $this->assertProvidedMatches($input, 'total', $total, $precision);

        foreach (['base_shipping' => $baseShipping, 'threshold_waiver' => $thresholdWaiver, 'coupon_waiver' => $couponWaiver, 'fast_shipping_fee' => $fastShippingFee] as $field => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("shipping_breakdown.{$field} cannot be negative.");
            }
        }
        if ($codFee !== null && $codFee < 0) {
            throw new InvalidArgumentException('shipping_breakdown.cod_fee cannot be negative.');
        }
        if ($thresholdWaiver + $couponWaiver - $baseShipping > $this->epsilon($precision)) {
            throw new InvalidArgumentException('shipping_breakdown waivers cannot exceed base_shipping.');
        }

        return [
            'version' => self::VERSION,
            'currency' => $currencyCode,
            'precision' => $precision,
            'base_shipping' => $baseShipping,
            'threshold_waiver' => $thresholdWaiver,
            'coupon_waiver' => $couponWaiver,
            'cod_fee' => $codFee,
            'fast_shipping_fee' => $fastShippingFee,
            'net_shipping' => $netShipping,
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed>|null $breakdown
     */
    public function validateAgainstOrder(?array $breakdown, float $shippingPrice, ?float $codFee, float $fastShippingFee, string $currencyCode): void
    {
        if ($breakdown === null) {
            return;
        }

        // COD fee is disabled in v1: any non-null cod_fee is rejected.
        $normalized = $this->build(array_merge($breakdown, ['currency' => $breakdown['currency'] ?? $currencyCode]), allowCodFee: false);

        $precision = (int) $normalized['precision'];
        $this->assertEqual($normalized['net_shipping'], $shippingPrice, $precision, 'shipping_price');
        $this->assertNullableEqual($normalized['cod_fee'], $codFee, $precision, 'cod_fee');
        $this->assertEqual($normalized['fast_shipping_fee'], $fastShippingFee, $precision, 'fast_shipping_fee');

        if (strtoupper((string) $normalized['currency']) !== strtoupper($currencyCode)) {
            throw new InvalidArgumentException('shipping_breakdown.currency does not match order currency.');
        }
    }

    private function resolvePrecision(string $currencyCode, mixed $provided): int
    {
        $currency = Currency::query()->where('code', $currencyCode)->first();
        $precision = $currency?->decimal_places;
        if ($precision === null) {
            $precision = CurrencyPrecision::decimalsFor($currencyCode);
        }
        $precision = (int) $precision;
        if ($precision < 0 || $precision > 6) {
            throw new InvalidArgumentException('shipping_breakdown.precision is invalid.');
        }
        if ($provided !== null && (int) $provided !== $precision) {
            throw new InvalidArgumentException('shipping_breakdown.precision does not match currency definition.');
        }

        return $precision;
    }

    private function money(mixed $value, string $field, int $precision): float
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException("shipping_breakdown.{$field} is required.");
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("shipping_breakdown.{$field} must be numeric.");
        }

        return $this->round((float) $value, $precision);
    }

    private function nullableMoney(mixed $value, string $field, int $precision): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("shipping_breakdown.{$field} must be numeric.");
        }

        return $this->round((float) $value, $precision);
    }

    /** @param array<string, mixed> $input */
    private function assertProvidedMatches(array $input, string $field, float $expected, int $precision): void
    {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            return;
        }
        if (!is_numeric($input[$field])) {
            throw new InvalidArgumentException("shipping_breakdown.{$field} must be numeric.");
        }
        if (abs($this->round((float) $input[$field], $precision) - $expected) > $this->epsilon($precision)) {
            throw new InvalidArgumentException("shipping_breakdown.{$field} is inconsistent.");
        }
    }

    private function assertEqual(float $actual, float $expected, int $precision, string $field): void
    {
        if (abs($this->round($actual, $precision) - $this->round($expected, $precision)) > $this->epsilon($precision)) {
            throw new InvalidArgumentException("shipping_breakdown does not reconcile with order {$field}.");
        }
    }

    private function assertNullableEqual(?float $actual, ?float $expected, int $precision, string $field): void
    {
        if ($actual === null && $expected === null) {
            return;
        }
        if ($actual === null || $expected === null) {
            throw new InvalidArgumentException("shipping_breakdown does not reconcile with order {$field}.");
        }
        $this->assertEqual($actual, $expected, $precision, $field);
    }

    private function round(float $value, int $precision): float
    {
        return round($value, $precision);
    }

    private function epsilon(int $precision): float
    {
        return 1 / (10 ** $precision) / 2;
    }
}

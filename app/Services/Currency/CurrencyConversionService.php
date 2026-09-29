<?php

namespace App\Services\Currency;

use App\DTOs\CurrencyConversionResult;
use App\Exceptions\CurrencyRateNotFoundException;
use App\Models\CurrencyRate;

class CurrencyConversionService
{
    private const SCALE = 6;

    public function convert(float|string $amount, string $fromCode, string $toCode, ?string $date = null): CurrencyConversionResult
    {
        $fromCode = strtoupper($fromCode);
        $toCode = strtoupper($toCode);
        $date = $date ?? now()->toDateString();
        $amount = (string) $amount;

        if ($fromCode === $toCode) {
            return new CurrencyConversionResult(
                amount: (float) $amount,
                convertedAmount: round((float) $amount, 2),
                rate: '1',
                effectiveDate: $date,
                fromCode: $fromCode,
                toCode: $toCode,
                sourceRate: '1',
                targetRate: '1',
            );
        }

        $sourceRate = $this->resolveRate($fromCode, $date);
        $targetRate = $this->resolveRate($toCode, $date);

        $converted = bcdiv(bcmul($amount, $targetRate, self::SCALE), $sourceRate, self::SCALE);

        // effectiveDate is the REQUEST date (the day the conversion is
        // performed for), while sourceRate/targetRate are the latest stored
        // rows <= that date. Order snapshots therefore record the checkout
        // day in currency_rate_date even when the rate value itself comes
        // from an earlier row — never reinterpret it as the row's own date.
        return new CurrencyConversionResult(
            amount: (float) $amount,
            convertedAmount: round((float) $converted, 2),
            rate: bcdiv($targetRate, $sourceRate, self::SCALE),
            effectiveDate: $date,
            fromCode: $fromCode,
            toCode: $toCode,
            sourceRate: $sourceRate,
            targetRate: $targetRate,
        );
    }

    private function resolveRate(string $currencyCode, string $date): string
    {
        $rate = CurrencyRate::query()
            ->whereHas('currency', fn ($query) => $query->where('code', $currencyCode))
            ->whereDate('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->value('exchange_rate');

        if ($rate === null) {
            throw CurrencyRateNotFoundException::forCurrency($currencyCode, $date);
        }

        return (string) $rate;
    }
}

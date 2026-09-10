<?php

namespace App\Services\Currency;

use App\Enums\RateMode;
use App\Enums\RateSource;
use App\Exceptions\CurrencyInUseException;
use App\Models\CurrencyRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CurrencyRateService
{
    public function __construct(private CurrencyService $currencyService)
    {
    }

    public function store(array $data): CurrencyRate
    {
        $rate = DB::transaction(function () use ($data): CurrencyRate {
            $currency = \App\Models\Currency::query()
                ->whereKey($data['currency_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $rate = CurrencyRate::query()
                ->where('currency_id', $currency->getKey())
                ->whereDate('effective_date', $data['effective_date'])
                ->lockForUpdate()
                ->first();

            $values = [
                'exchange_rate' => $data['exchange_rate'],
                'source' => RateSource::MANUAL->value,
                'provider' => null,
            ];

            if ($rate) {
                $rate->update($values);
            } else {
                $rate = CurrencyRate::create(array_merge([
                    'currency_id' => $currency->getKey(),
                    'effective_date' => $data['effective_date'],
                ], $values));
            }

            if (now()->isSameDay($rate->effective_date)) {
                $currency->rate_mode = RateMode::MANUAL;
                $currency->manual_rate = (string) $data['exchange_rate'];
                $currency->effective_rate_updated_at = now();
                $currency->save();
            }

            return $rate->fresh();
        });

        $this->currencyService->invalidatePriceCaches();

        return $rate;
    }

    public function update(CurrencyRate $rate, array $data): CurrencyRate
    {
        $rate = DB::transaction(function () use ($rate, $data): CurrencyRate {
            $currency = \App\Models\Currency::query()
                ->whereKey($rate->currency_id)
                ->lockForUpdate()
                ->firstOrFail();

            $rate = CurrencyRate::query()->lockForUpdate()->findOrFail($rate->getKey());
            $rate->update([
                'exchange_rate' => $data['exchange_rate'],
                'source' => RateSource::MANUAL->value,
                'provider' => null,
            ]);

            if (now()->isSameDay($rate->effective_date)) {
                $currency->rate_mode = RateMode::MANUAL;
                $currency->manual_rate = (string) $data['exchange_rate'];
                $currency->effective_rate_updated_at = now();
                $currency->save();
            }

            return $rate->fresh();
        });

        $this->currencyService->invalidatePriceCaches();

        return $rate;
    }

    public function delete(CurrencyRate $rate): void
    {
        DB::transaction(function () use ($rate): void {
            $currency = \App\Models\Currency::query()
                ->whereKey($rate->currency_id)
                ->lockForUpdate()
                ->firstOrFail();

            $rate = CurrencyRate::query()->lockForUpdate()->findOrFail($rate->getKey());
            $effectiveRate = CurrencyRate::query()
                ->where('currency_id', $currency->getKey())
                ->whereDate('effective_date', '<=', now()->toDateString())
                ->orderByDesc('effective_date')
                ->orderByDesc('id')
                ->first();

            if ($effectiveRate?->getKey() === $rate->getKey()) {
                throw CurrencyInUseException::isOnlyEffectiveRate();
            }

            $rate->delete();
        });

        $this->currencyService->invalidatePriceCaches();
    }

    public function list(?int $currencyId, ?string $effectiveDate, ?string $dateFrom, ?string $dateTo, ?string $code, int $limit): LengthAwarePaginator
    {
        return CurrencyRate::query()
            ->with('currency')
            ->when($currencyId, fn ($query) => $query->where('currency_id', $currencyId))
            ->when($effectiveDate, fn ($query) => $query->whereDate('effective_date', $effectiveDate))
            ->when($dateFrom, fn ($query) => $query->whereDate('effective_date', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->whereDate('effective_date', '<=', $dateTo))
            ->when($code, fn ($query) => $query->whereHas('currency', fn ($q) => $q->where('code', $code)))
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->paginate($limit);
    }
}

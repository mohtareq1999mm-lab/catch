<?php

namespace App\Services\Currency;

use App\Contracts\ExchangeRateProviderInterface;
use App\Enums\RateMode;
use App\Enums\RateSource;
use App\Exceptions\ExchangeRateValidationException;
use App\Models\Currency;
use App\Models\CurrencyRate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExchangeRateSyncService
{
    public function __construct(
        private readonly ExchangeRateProviderInterface $provider,
        private readonly CurrencyService $currencyService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(bool $dryRun = false): array
    {
        $lock = Cache::lock(
            'currency-rate-sync',
            (int) config('currency.sync.lock_ttl', 3600),
        );

        if (!$lock->get()) {
            Log::notice('currency.sync.skipped', ['reason' => 'lock_unavailable']);

            return [
                'status' => 'skipped',
                'reason' => 'lock_unavailable',
                'changed' => 0,
                'manual' => 0,
                'anomalies' => 0,
            ];
        }

        $startedAt = microtime(true);

        try {
            $currencies = Currency::query()
                ->active()
                ->orderBy('id')
                ->get();

            $anchor = strtoupper((string) config('currency.anchor', ''));
            if ($anchor === '') {
                throw new ExchangeRateValidationException('Currency rate anchor is not configured.');
            }

            Log::info('currency.sync.started', [
                'provider' => $this->provider->name(),
                'anchor' => $anchor,
                'currency_count' => $currencies->count(),
                'dry_run' => $dryRun,
            ]);

            if ($currencies->isEmpty()) {
                return [
                    'status' => 'success',
                    'changed' => 0,
                    'manual' => 0,
                    'anomalies' => 0,
                ];
            }

            $codes = $currencies->pluck('code')->map(fn ($code) => strtoupper((string) $code))->all();
            $snapshot = $this->provider->getLatestRates($anchor, $codes);

            $this->validateSnapshot($snapshot, $currencies, $anchor);
            $comparisons = $this->compareRates($currencies, $snapshot->rates);
            $anomalies = collect($comparisons)->where('anomaly', true)->count();

            if ($dryRun) {
                Log::info('currency.sync.success', [
                    'provider' => $snapshot->provider,
                    'anchor' => $anchor,
                    'dry_run' => true,
                    'anomalies' => $anomalies,
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                ]);

                return [
                    'status' => 'success',
                    'dry_run' => true,
                    'changed' => 0,
                    'manual' => $currencies->where('rate_mode', RateMode::MANUAL)->count(),
                    'anomalies' => $anomalies,
                    'comparisons' => $comparisons,
                ];
            }

            $this->rejectEstablishedAnomalies($comparisons);

            $result = DB::transaction(function () use ($currencies, $snapshot): array {
                $lockedCurrencies = Currency::query()
                    ->whereIn('id', $currencies->pluck('id')->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $today = now()->toDateString();
                $changed = 0;
                $manual = 0;

                foreach ($currencies as $currency) {
                    $lockedCurrency = $lockedCurrencies->get($currency->getKey());
                    if (!$lockedCurrency || !$lockedCurrency->is_active) {
                        continue;
                    }

                    $code = strtoupper($lockedCurrency->code);
                    $providerRate = $snapshot->rates[$code];
                    $lockedCurrency->provider_rate = $providerRate;
                    $lockedCurrency->provider = $snapshot->provider;
                    $lockedCurrency->provider_rate_at = $snapshot->providerRateAt;
                    $lockedCurrency->last_synced_at = now();

                    $mode = $lockedCurrency->rate_mode instanceof RateMode
                        ? $lockedCurrency->rate_mode
                        : RateMode::tryFrom((string) $lockedCurrency->rate_mode) ?? RateMode::MANUAL;

                    if ($mode === RateMode::MANUAL) {
                        $manual++;
                        $lockedCurrency->save();
                        continue;
                    }

                    $rate = CurrencyRate::query()
                        ->where('currency_id', $lockedCurrency->getKey())
                        ->whereDate('effective_date', $today)
                        ->lockForUpdate()
                        ->first();

                    $effectiveChanged = !$rate || bccomp((string) $rate->exchange_rate, $providerRate, 10) !== 0;
                    $values = [
                        'exchange_rate' => $providerRate,
                        'source' => RateSource::PROVIDER->value,
                        'provider' => $snapshot->provider,
                    ];

                    if ($rate) {
                        $rate->update($values);
                    } else {
                        CurrencyRate::create(array_merge([
                            'currency_id' => $lockedCurrency->getKey(),
                            'effective_date' => $today,
                        ], $values));
                    }

                    if ($effectiveChanged) {
                        $lockedCurrency->effective_rate_updated_at = now();
                        $changed++;
                    }

                    $lockedCurrency->save();
                }

                return ['changed' => $changed, 'manual' => $manual];
            });

            $this->currencyService->forgetRateCache();
            if ($result['changed'] > 0) {
                $this->currencyService->invalidatePriceCaches();
            }

            Log::info('currency.sync.success', [
                'provider' => $snapshot->provider,
                'anchor' => $anchor,
                'changed' => $result['changed'],
                'manual' => $result['manual'],
                'anomalies' => $anomalies,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            return array_merge([
                'status' => 'success',
                'anomalies' => $anomalies,
                'comparisons' => $comparisons,
            ], $result);
        } catch (\Throwable $exception) {
            report($exception);
            Log::error('currency.sync.failed', [
                'provider' => $this->provider->name(),
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            return [
                'status' => 'failed',
                'changed' => 0,
                'manual' => 0,
                'anomalies' => 0,
                'error' => $exception->getMessage(),
            ];
        } finally {
            $lock->release();
        }
    }

    private function validateSnapshot(object $snapshot, $currencies, string $anchor): void
    {
        if ($snapshot->anchor !== $anchor) {
            throw new ExchangeRateValidationException('Provider snapshot anchor does not match configuration.');
        }

        if ($snapshot->providerRateAt === null || $snapshot->providerRateAt->isFuture()) {
            throw new ExchangeRateValidationException('Provider timestamp is invalid.');
        }

        $maxAge = (int) config('currency.sync.provider_data_max_age_hours', 72);
        if ($maxAge > 0 && $snapshot->providerRateAt->lt(now()->subHours($maxAge))) {
            throw new ExchangeRateValidationException('Provider data is stale.');
        }

        foreach ($currencies as $currency) {
            $code = strtoupper($currency->code);
            $rate = $snapshot->rates[$code] ?? null;

            if ($rate === null || !$this->isValidRate($rate)) {
                throw new ExchangeRateValidationException("Provider rate is missing or invalid for {$code}.");
            }
        }
    }

    /**
     * @param iterable<Currency> $currencies
     * @param array<string, string> $rates
     * @return array<int, array<string, mixed>>
     */
    private function compareRates(iterable $currencies, array $rates): array
    {
        $comparisons = [];
        $maxChange = (string) config('currency.validation.max_rate_change_percent', '30');

        foreach ($currencies as $currency) {
            $code = strtoupper($currency->code);
            $currentRate = CurrencyRate::query()
                ->where('currency_id', $currency->getKey())
                ->whereDate('effective_date', '<=', now()->toDateString())
                ->orderByDesc('effective_date')
                ->orderByDesc('id')
                ->value('exchange_rate');
            $providerRate = $rates[$code];
            $baseline = $currency->provider_rate ?: $currentRate;
            $percentage = '0';

            if ($baseline !== null && bccomp((string) $baseline, '0', 10) > 0) {
                $difference = bcsub($providerRate, (string) $baseline, 10);
                $difference = str_starts_with($difference, '-') ? substr($difference, 1) : $difference;
                $percentage = bcmul(bcdiv($difference, (string) $baseline, 10), '100', 4);
            }

            $anomaly = bccomp($percentage, $maxChange, 4) > 0;

            $comparisons[] = [
                'currency' => $code,
                'current_rate' => $currentRate,
                'provider_rate' => $providerRate,
                'difference_percent' => $percentage,
                'anomaly' => $anomaly,
                'initial' => $currency->provider_rate === null,
                'mode' => $currency->rate_mode?->value ?? (string) $currency->rate_mode,
            ];
        }

        return $comparisons;
    }

    /**
     * @param array<int, array<string, mixed>> $comparisons
     */
    private function rejectEstablishedAnomalies(array $comparisons): void
    {
        foreach ($comparisons as $comparison) {
            if ($comparison['anomaly'] && !$comparison['initial']) {
                Log::warning('currency.sync.validation_failed', [
                    'currency' => $comparison['currency'],
                    'difference_percent' => $comparison['difference_percent'],
                ]);

                throw new ExchangeRateValidationException(
                    "Provider rate anomaly detected for {$comparison['currency']}."
                );
            }
        }
    }

    private function isValidRate(mixed $value): bool
    {
        $value = (string) $value;

        if (!preg_match('/^\d+(?:\.\d{1,10})?$/', $value) || bccomp($value, '0', 10) <= 0) {
            return false;
        }

        return bccomp($value, (string) config('currency.validation.hard_max_rate', '1000000'), 10) <= 0;
    }
}

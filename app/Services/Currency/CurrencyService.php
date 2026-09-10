<?php

namespace App\Services\Currency;

use App\DTOs\CurrencyConversionResult;
use App\Enums\FrontendResource;
use App\Enums\RateMode;
use App\Enums\RateSource;
use App\Exceptions\CurrencyInactiveException;
use App\Exceptions\CurrencyInUseException;
use App\Exceptions\CurrencyRateNotFoundException;
use App\Models\Currency;
use App\Models\CurrencyRate;
use App\Traits\HasCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Jobs\LogActivityJob;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;

class CurrencyService
{
    use HasCache;
    public const PRODUCT_STRATEGY_TYPES = [
        'best_product_sales',
        'brands_product',
        'new_arrivals',
        'all_product_discounts',
        'product_discount_today_or_low_qty',
        'flash_sales_product',
        'flash_sales_end_today',
        'product_for_parent_category',
        'flash_sales_end_week',
    ];

    private const SCALE = 6;

    private ?string $baseCode = null;
    private ?string $catalogCode = null;
    private ?string $effectiveCode = null;
    private ?bool $currencySelectionEnabled = null;
    private ?Currency $baseCurrency = null;
    private ?Currency $catalogCurrency = null;
    private ?Currency $effectiveCurrency = null;
    private array $rateCache = [];

    public function __construct(
        private CurrencyConversionService $conversionService,
        private UserCurrencyPreferenceService $preferenceService,
    ) {}

    public function getBaseCode(): string
    {
        if ($this->baseCode !== null) {
            return $this->baseCode;
        }

        $options = $this->settingsOptions();

        return $this->baseCode = strtoupper((string) ($options['base_currency_code'] ?? config('shop.default_currency', 'USD')));
    }

    public function getCatalogCode(): string
    {
        if ($this->catalogCode !== null) {
            return $this->catalogCode;
        }

        $options = $this->settingsOptions();

        return $this->catalogCode = strtoupper((string) ($options['catalog_currency_code'] ?? config('shop.default_currency', 'USD')));
    }

    public function getBaseCurrency(): ?Currency
    {
        return $this->baseCurrency ??= Currency::query()->where('code', $this->getBaseCode())->first();
    }

    public function getCatalogCurrency(): ?Currency
    {
        return $this->catalogCurrency ??= Currency::query()->where('code', $this->getCatalogCode())->first();
    }

    public function getEffectiveCode(?User $user = null): string
    {
        if ($this->effectiveCode !== null) {
            return $this->effectiveCode;
        }

        // Currency selection is disabled by default. When disabled the customer
        // cannot select a display/checkout currency, so the effective currency
        // always resolves to the catalog currency regardless of any stored user
        // preference or guest cookie.
        if (!$this->isCurrencySelectionEnabled()) {
            return $this->effectiveCode = $this->getCatalogCode();
        }

$user ??= auth()->user() ?? auth('sanctum')->user();

        // The user/guest preference feature is additive. When its tables are not
        // present yet (legacy install or test schema without them) fall back to
        // the catalog code instead of failing checkout.
        if (Schema::hasTable('user_preferences') && Schema::hasTable('currencies')) {
            $preferenceCode = $this->preferenceService->getUserPreference($user);
            if ($preferenceCode !== null && !$this->preferenceService->isValidActiveCurrency($preferenceCode)) {
                if ($user) {
                    $this->preferenceService->clearUserPreference($user);
                }
                $preferenceCode = null;
            }

            if ($preferenceCode !== null) {
                return $this->effectiveCode = $preferenceCode;
            }

            $headerCode = $this->preferenceService->getHeaderCurrencyCode();
            if ($headerCode !== null && !$this->preferenceService->isValidActiveCurrency($headerCode)) {
                $headerCode = null;
            }

            if ($headerCode !== null) {
                return $this->effectiveCode = $headerCode;
            }
        }

        return $this->effectiveCode = $this->getCatalogCode();
    }

    public function getEffectiveCurrency(): ?Currency
    {
        return $this->effectiveCurrency ??= Currency::query()->where('code', $this->getEffectiveCode())->first();
    }

    public function isCurrencySelectionEnabled(): bool
    {
        if ($this->currencySelectionEnabled !== null) {
            return $this->currencySelectionEnabled;
        }

        $options = $this->settingsOptions();

        return $this->currencySelectionEnabled = (bool) ($options['currency_selection_enabled'] ?? false);
    }

    public function forgetEffectiveCode(): void
    {
        $this->effectiveCode = null;
        $this->effectiveCurrency = null;
        $this->currencySelectionEnabled = null;
    }

    public function forgetRateCache(): void
    {
        $this->rateCache = [];
    }

    public function convert(float|string $amount, string $fromCode, string $toCode, ?string $date = null): CurrencyConversionResult
    {
        return $this->conversionService->convert($amount, $fromCode, $toCode, $date);
    }

    public function convertPrice(float|string $amount, string $fromCode, string $toCode, ?string $date = null): float
    {
        $fromCode = strtoupper($fromCode);
        $toCode = strtoupper($toCode);
        $date = $date ?? now()->toDateString();
        $amount = (string) $amount;

        if ($fromCode === $toCode) {
            return round((float) $amount, 2);
        }

        $targetRate = $this->resolveRate($toCode, $date);
        $sourceRate = $this->resolveRate($fromCode, $date);

        return round((float) bcdiv(bcmul($amount, $targetRate, self::SCALE), $sourceRate, self::SCALE), 2);
    }

    public function storeCurrency(array $data): Currency
    {
        $currency = Currency::create($data);
        $this->invalidatePriceCaches();

        return $currency;
    }

    public function updateCurrency(Currency $currency, array $data): Currency
    {
        $currency->update($data);
        $this->invalidatePriceCaches();

        return $currency->fresh();
    }

    public function deleteCurrency(Currency $currency): void
    {
        if ($currency->code === $this->getBaseCode()) {
            throw CurrencyInUseException::isBaseCurrency();
        }

        if ($currency->rates()->exists()) {
            throw CurrencyInUseException::referencedByRates();
        }

        $currency->delete();
        $this->invalidatePriceCaches();
    }

    public function setBaseCurrency(Currency $currency): void
    {
        DB::transaction(function () use ($currency) {
            $settings = Settings::query()->lockForUpdate()->first();

            if (!$settings) {
                throw new \RuntimeException('Settings record not found.');
            }

            // IMMUTABILITY GUARD: Prevent base currency change when financial orders exist
            // Once the first order reaches 'completed' + 'payment-success', the base currency
            // used for converted_total_price becomes the immutable financial reporting currency.
            // This prevents mixed-currency aggregation (e.g., summing USD + KWD values).
            $hasFinancialOrders = \Marvel\Database\Models\Order::query()
                ->where('status', \Marvel\Database\Models\Order::ORDER_STATUS_COMPLETED)
                ->where('payment_status', \Marvel\Database\Models\Order::PAYMENT_STATUS_SUCCESS)
                ->exists();

            if ($hasFinancialOrders) {
                throw CurrencyInUseException::hasFinancialOrders();
            }

            if (!$currency->is_active) {
                throw CurrencyInactiveException::forCurrency($currency->code);
            }

            $hasRate = CurrencyRate::query()
                ->where('currency_id', $currency->getKey())
                ->whereDate('effective_date', '<=', now()->toDateString())
                ->exists();

            if (!$hasRate) {
                throw CurrencyRateNotFoundException::forCurrency($currency->code, now()->toDateString());
            }

            $options = $settings->options ?? [];
            $options['base_currency_code'] = $currency->code;
            $options['currency'] = $currency->code;

            $settings->options = $options;
            $settings->save();

         $this->flushTag(FrontendResource::SETTINGS->value);
        });

        $this->baseCode = $currency->code;
        $this->baseCurrency = $currency;

        $this->invalidatePriceCaches(flushSettings: true);
    }
    public function setCatalogCurrency(Currency $currency): void
    {
        DB::transaction(function () use ($currency) {
            $settings = Settings::query()->lockForUpdate()->first();

            if (!$settings) {
                throw new \RuntimeException('Settings record not found.');
            }

            if (!$currency->is_active) {
                throw CurrencyInactiveException::forCurrency($currency->code);
            }

            $hasRate = CurrencyRate::query()
                ->where('currency_id', $currency->getKey())
                ->whereDate('effective_date', '<=', now()->toDateString())
                ->exists();

            if (!$hasRate) {
                throw CurrencyRateNotFoundException::forCurrency(
                    $currency->code,
                    now()->toDateString()
                );
            }

            $options = $settings->options ?? [];

            // Change ONLY the Catalog Currency
            $options['catalog_currency_code'] = $currency->code;

            $settings->options = $options;
            $settings->save();

            $this->flushTag(FrontendResource::SETTINGS->value);
        });

        $this->catalogCode = $currency->code;
        $this->catalogCurrency = $currency;

        $this->invalidatePriceCaches(flushSettings: true);
    }

    public function setRateMode(Currency $currency, RateMode $mode, ?string $manualRate = null): Currency
    {
        $before = [];

        // AUTO must be provider-driven: if no fresh provider rate exists, fetch it automatically.
        $autoFreshRate = null;
        $autoFreshProvider = null;
        $autoFreshProviderRateAt = null;
        if ($mode === RateMode::AUTO) {
            $current = Currency::query()->findOrFail($currency->getKey());
            $needsFresh = !$current->provider_rate
                || !$current->provider_rate_at
                || $current->provider_rate_at->lt(now()->subHours((int) config('currency.sync.provider_data_max_age_hours', 72)));

            // Anchor currency is always 1; no provider call needed.
            $anchor = strtoupper((string) config('currency.anchor', 'USD'));
            if (strtoupper($current->code) === $anchor) {
                $autoFreshRate = '1.0000000000';
                $autoFreshProvider = $current->provider ?? 'frankfurter';
                $autoFreshProviderRateAt = now();
                $needsFresh = false;
            }

            if ($needsFresh) {
                $provider = app(\App\Contracts\ExchangeRateProviderInterface::class);
                $snapshot = $provider->getLatestRates($anchor, [strtoupper($current->code)]);
                $code = strtoupper($current->code);
                if (!isset($snapshot->rates[$code])) {
                    throw CurrencyRateNotFoundException::forCurrency($current->code, now()->toDateString());
                }
                $autoFreshRate = (string) $snapshot->rates[$code];
                $autoFreshProvider = $snapshot->provider;
                $autoFreshProviderRateAt = $snapshot->providerRateAt ?? now();
            }
        }

        $updated = DB::transaction(function () use ($currency, $mode, $manualRate, &$before, $autoFreshRate, $autoFreshProvider, $autoFreshProviderRateAt): Currency {
            $lockedCurrency = Currency::query()->lockForUpdate()->findOrFail($currency->getKey());
            $before = [
                'rate_mode' => $lockedCurrency->rate_mode?->value ?? (string) $lockedCurrency->rate_mode,
                'manual_rate' => $lockedCurrency->manual_rate,
                'provider_rate' => $lockedCurrency->provider_rate,
                'effective_rate' => $lockedCurrency->effectiveRate(),
            ];

            $today = now()->toDateString();
            $rate = CurrencyRate::query()
                ->where('currency_id', $lockedCurrency->getKey())
                ->whereDate('effective_date', $today)
                ->lockForUpdate()
                ->first();

            if ($mode === RateMode::MANUAL) {
                $manualRate = $this->normalizePositiveRate($manualRate);

                $values = [
                    'exchange_rate' => $manualRate,
                    'source' => RateSource::MANUAL->value,
                    'provider' => null,
                ];

                if ($rate) {
                    $rate->update($values);
                } else {
                    $rate = CurrencyRate::create(array_merge([
                        'currency_id' => $lockedCurrency->getKey(),
                        'effective_date' => $today,
                    ], $values));
                }

                $lockedCurrency->rate_mode = RateMode::MANUAL;
                $lockedCurrency->manual_rate = $manualRate;
            } else {
                $previousProviderRate = $lockedCurrency->provider_rate;
                // If we just fetched a fresh rate for this transition, persist it first.
                if ($autoFreshRate !== null) {
                    $lockedCurrency->provider_rate = $this->normalizePositiveRate($autoFreshRate);
                    $lockedCurrency->provider = $autoFreshProvider ?? $lockedCurrency->provider;
                    $lockedCurrency->provider_rate_at = $autoFreshProviderRateAt;
                    $lockedCurrency->last_synced_at = now();
                }

                $providerRate = $this->normalizePositiveRate($lockedCurrency->provider_rate);

                if (!$lockedCurrency->provider_rate_at || $lockedCurrency->provider_rate_at->lt(now()->subHours((int) config('currency.sync.provider_data_max_age_hours', 72)))) {
                    throw CurrencyRateNotFoundException::forCurrency($lockedCurrency->code, now()->toDateString());
                }

                if ($previousProviderRate !== null) {
                    $maxChange = (string) config('currency.validation.max_rate_change_percent', '30');
                    $difference = bcsub($providerRate, (string) $previousProviderRate, 10);
                    $difference = str_starts_with($difference, '-') ? substr($difference, 1) : $difference;
                    if (bccomp((string) $previousProviderRate, '0', 10) > 0) {
                        $percentage = bcmul(bcdiv($difference, (string) $previousProviderRate, 10), '100', 4);
                        if (bccomp($percentage, $maxChange, 4) > 0) {
                            throw new \App\Exceptions\ExchangeRateValidationException(
                                "Provider rate anomaly detected for {$lockedCurrency->code}."
                            );
                        }
                    }
                }

                $effectiveChanged = !$rate || bccomp((string) $rate->exchange_rate, $providerRate, 10) !== 0;
                $values = [
                    'exchange_rate' => $providerRate,
                    'source' => RateSource::PROVIDER->value,
                    'provider' => $lockedCurrency->provider,
                ];

                if ($rate) {
                    $rate->update($values);
                } else {
                    $rate = CurrencyRate::create(array_merge([
                        'currency_id' => $lockedCurrency->getKey(),
                        'effective_date' => $today,
                    ], $values));
                }

                $lockedCurrency->rate_mode = RateMode::AUTO;
                $lockedCurrency->manual_rate = null;

                if ($effectiveChanged) {
                    $lockedCurrency->effective_rate_updated_at = now();
                }
            }

            $lockedCurrency->save();

            return $lockedCurrency->fresh();
        });

        $this->invalidatePriceCaches();

        LogActivityJob::dispatch(
            get_class($updated),
            $updated->getKey(),
            auth('sanctum')->id() ?? auth()->id(),
            'rateModeChanged',
            'currencies',
            __('activity.currency_rate_mode_changed'),
            ['old' => $before, 'new' => [
                'rate_mode' => $updated->rate_mode?->value,
                'manual_rate' => $updated->manual_rate,
                'provider_rate' => $updated->provider_rate,
                'effective_rate' => $updated->effectiveRate(),
            ]],
        );

        return $updated;
    }

    public function invalidatePriceCaches(bool $flushSettings = false): void
    {
        $tags = array_merge(
            [FrontendResource::CURRENCIES->value, FrontendResource::PRODUCTS->value],
            $this->productStrategyTags(),
        );

        if ($flushSettings) {
            $tags[] = FrontendResource::SETTINGS->value;
        }

        Cache::tags(array_values(array_unique($tags)))->flush();

        \App\Services\General\HomeService::clearCache();
        $this->forgetRateCache();
    }

    private function productStrategyTags(): array
    {
        return array_map(
            fn(string $type) => FrontendResource::PRODUCTS->value . '_' . $type,
            self::PRODUCT_STRATEGY_TYPES,
        );
    }

    private function settingsOptions(): array
    {
        $settings = Settings::query()->first();

        return $settings ? ($settings->options ?? []) : [];
    }

    private function resolveRate(string $currencyCode, string $date): string
    {
        $cacheKey = $currencyCode . '|' . $date;

        if (array_key_exists($cacheKey, $this->rateCache)) {
            return $this->rateCache[$cacheKey];
        }

        $rate = CurrencyRate::query()
            ->whereHas('currency', fn($query) => $query->where('code', $currencyCode))
            ->whereDate('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->value('exchange_rate');

        if ($rate === null) {
            throw CurrencyRateNotFoundException::forCurrency($currencyCode, $date);
        }

        return $this->rateCache[$cacheKey] = (string) $rate;
    }

    private function normalizePositiveRate(mixed $value): string
    {
        $value = (string) $value;

        if (!preg_match('/^\d+(?:\.\d{1,10})?$/', $value) || bccomp($value, '0', 10) <= 0) {
            throw new \InvalidArgumentException('Exchange rate must be a positive decimal value.');
        }

        if (bccomp($value, (string) config('currency.validation.hard_max_rate', '1000000'), 10) > 0) {
            throw new \InvalidArgumentException('Exchange rate exceeds the configured maximum.');
        }

        return bcadd($value, '0', (int) config('currency.validation.precision', 10));
    }
}

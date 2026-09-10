<?php

namespace App\Services\Currency\Providers;

use App\Contracts\ExchangeRateProviderInterface;
use App\DTOs\ExchangeRateSnapshot;
use App\Exceptions\ExchangeRateProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class FrankfurterProvider implements ExchangeRateProviderInterface
{
    public function name(): string
    {
        return 'frankfurter';
    }

    public function getLatestRates(string $baseCurrency, array $quotes = []): ExchangeRateSnapshot
    {
        $baseCurrency = strtoupper(trim($baseCurrency));
        $anchor = strtoupper((string) config('currency.anchor', 'USD'));

        $baseUrl = rtrim((string) config('currency.provider.base_url', 'https://api.frankfurter.dev'), '/');
        // Prefer v2 which supports 200+ currencies including SAR/KWD/AED/EGP; fallback to v1 shape is handled in parsing.
        $url = $baseUrl . '/v2/rates?base=' . $baseCurrency;
        if ($quotes !== []) {
            $url .= '&quotes=' . implode(',', array_map('strtoupper', array_unique($quotes)));
        }

        $response = null;
        $lastConnectionException = null;
        $attempts = max(0, (int) config('currency.provider.retries', 2)) + 1;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->connectTimeout((int) config('currency.provider.connect_timeout', 3))
                    ->timeout((int) config('currency.provider.timeout', 10))
                    ->get($url);
                $lastConnectionException = null;
            } catch (ConnectionException $exception) {
                $lastConnectionException = $exception;
            }

            $status = $response?->status();
            $retryableResponse = $status === 429 || ($status !== null && $status >= 500);

            if ($lastConnectionException === null && (!$retryableResponse || $attempt === $attempts - 1)) {
                break;
            }

            usleep((int) config('currency.provider.retry_sleep_ms', 500) * 1000);
        }

        if ($lastConnectionException !== null) {
            throw new ExchangeRateProviderException('Exchange rate provider connection failed.', 0, $lastConnectionException);
        }

        if ($response === null) {
            throw new ExchangeRateProviderException('Exchange rate provider returned no response.');
        }

        if (!$response->successful()) {
            throw new ExchangeRateProviderException(
                'Exchange rate provider returned an unsuccessful response.',
                $response->status(),
            );
        }

        $payload = $response->json();

        if (!is_array($payload)) {
            throw new ExchangeRateProviderException('Exchange rate provider returned an invalid response.');
        }

        // Support both v1 (object with base/rates/date) and v2 (array of {base,quote,rate,date})
        $providerBase = null;
        $providerDate = null;
        $rates = [];

        if (isset($payload['base']) && isset($payload['rates'])) {
            // v1 shape
            $providerBase = strtoupper((string) ($payload['base'] ?? ''));
            $providerRates = $payload['rates'] ?? null;
            $providerDate = $payload['date'] ?? null;

            if ($providerBase === '' || !is_array($providerRates) || $providerDate === null) {
                throw new ExchangeRateProviderException('Exchange rate provider response is missing required fields.');
            }

            if ($providerBase !== $baseCurrency) {
                throw new ExchangeRateProviderException('Provider base does not match requested anchor.');
            }

            foreach ($providerRates as $code => $value) {
                $code = strtoupper((string) $code);
                if (!$this->isDecimalString($value)) {
                    throw new ExchangeRateProviderException('Exchange rate provider returned a non-numeric rate.');
                }
                $rates[$code] = $this->normalizeRate((string) $value);
            }
        } elseif (array_is_list($payload)) {
            // v2 shape: [{base, quote, rate, date}, ...]
            if ($payload === []) {
                throw new ExchangeRateProviderException('Exchange rate provider response is missing required fields.');
            }
            $first = $payload[0];
            $providerBase = strtoupper((string) ($first['base'] ?? $baseCurrency));
            $providerDate = $first['date'] ?? null;

            if ($providerBase !== $baseCurrency) {
                throw new ExchangeRateProviderException('Provider base does not match requested anchor.');
            }

            foreach ($payload as $row) {
                $quote = strtoupper((string) ($row['quote'] ?? ''));
                $rate = $row['rate'] ?? null;
                $rowDate = $row['date'] ?? null;
                if ($quote === '' || $rate === null || $rowDate === null) {
                    throw new ExchangeRateProviderException('Exchange rate provider response is missing required fields.');
                }
                if (!$this->isDecimalString($rate)) {
                    throw new ExchangeRateProviderException('Exchange rate provider returned a non-numeric rate.');
                }
                $rates[$quote] = $this->normalizeRate((string) $rate);
                // Use earliest date as providerRateAt if multiple; keep first for now
                $providerDate ??= $rowDate;
            }
        } else {
            throw new ExchangeRateProviderException('Exchange rate provider returned an invalid response.');
        }

        // Frankfurter does not include base in rates; add anchor = 1
        $rates[$providerBase] = '1.0000000000';

        $rates = $this->normalizeToAnchor($rates, $providerBase, $anchor);

        if ($quotes !== []) {
            $requiredCodes = array_map('strtoupper', array_values(array_unique(array_merge([$anchor], $quotes))));
            $rates = array_intersect_key($rates, array_flip($requiredCodes));
        }

        return new ExchangeRateSnapshot(
            provider: $this->name(),
            providerBase: $providerBase,
            anchor: $anchor,
            providerRateAt: $this->providerRateAt(['date' => $providerDate]),
            rates: $rates,
        );
    }

    /**
     * @param array<string, string> $rates
     * @return array<string, string>
     */
    private function normalizeToAnchor(array $rates, string $providerBase, string $anchor): array
    {
        if ($providerBase === $anchor) {
            $rates[$anchor] = '1.0000000000';

            return $rates;
        }

        $anchorRate = $rates[$anchor] ?? null;

        if ($anchorRate === null || bccomp($anchorRate, '0', 10) <= 0) {
            throw new ExchangeRateProviderException('Provider response cannot be normalized to the configured anchor.');
        }

        $normalized = [];
        foreach ($rates as $code => $rate) {
            $normalized[$code] = bcdiv($rate, $anchorRate, 10);
        }
        $normalized[$anchor] = '1.0000000000';

        return $normalized;
    }

    private function providerRateAt(array $payload): ?CarbonImmutable
    {
        $date = $payload['date'] ?? null;

        if (!is_string($date) || $date === '') {
            throw new ExchangeRateProviderException('Provider timestamp is missing.');
        }

        try {
            $parsed = CarbonImmutable::parse($date);
            if ($parsed->isFuture()) {
                throw new ExchangeRateProviderException('Provider timestamp is invalid.');
            }

            return $parsed->utc();
        } catch (\Throwable $e) {
            if ($e instanceof ExchangeRateProviderException) {
                throw $e;
            }
            throw new ExchangeRateProviderException('Provider timestamp is invalid.', 0, $e);
        }
    }

    private function isDecimalString(mixed $value): bool
    {
        return is_int($value)
            || (is_float($value) && is_finite($value))
            || (is_string($value) && preg_match('/^\d+(?:\.\d+)?$/', $value) === 1);
    }

    private function normalizeRate(string $value): string
    {
        $precision = (int) config('currency.validation.precision', 10);
        $decimalPosition = strpos($value, '.');

        if ($decimalPosition !== false && strlen($value) - $decimalPosition - 1 > $precision) {
            throw new ExchangeRateProviderException('Provider returned a rate with excessive precision.');
        }

        if (bccomp($value, '0', 10) <= 0) {
            throw new ExchangeRateProviderException('Provider returned a non-positive rate.');
        }

        return bcadd($value, '0', $precision);
    }
}

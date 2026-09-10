<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\Services\Currency\Providers\FrankfurterProvider;
use Illuminate\Support\Facades\Http;

class ExchangeRateProviderTest extends CurrencyTestCase
{
    public function test_provider_returns_a_normalized_snapshot(): void
    {
        config([
            'currency.anchor' => 'USD',
            'currency.provider.name' => 'frankfurter',
            'currency.provider.base_url' => 'https://provider.test',
        ]);

        Http::fake([
            'https://provider.test/*' => Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'date' => now()->toDateString(),
                'rates' => [
                    'KWD' => 0.30746,
                    'SAR' => 3.75,
                ],
            ]),
        ]);

        $snapshot = app(FrankfurterProvider::class)->getLatestRates('USD', ['USD', 'KWD', 'SAR']);

        $this->assertSame('USD', $snapshot->anchor);
        $this->assertSame('0.3074600000', $snapshot->rates['KWD']);
        $this->assertSame('3.7500000000', $snapshot->rates['SAR']);
        $this->assertSame('1.0000000000', $snapshot->rates['USD']);
        Http::assertSentCount(1);
    }

    public function test_provider_rejects_an_unsuccessful_response(): void
    {
        config([
            'currency.provider.name' => 'frankfurter',
            'currency.provider.base_url' => 'https://provider.test',
        ]);

        Http::fake(['https://provider.test/*' => Http::response([], 500)]);

        $this->expectException(\App\Exceptions\ExchangeRateProviderException::class);

        app(FrankfurterProvider::class)->getLatestRates('USD', ['USD']);
    }

    public function test_provider_retries_a_server_error_then_accepts_success(): void
    {
        config([
            'currency.anchor' => 'USD',
            'currency.provider.name' => 'frankfurter',
            'currency.provider.base_url' => 'https://provider.test',
            'currency.provider.retries' => 1,
        ]);

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return $attempts === 1
                ? Http::response([], 500)
                : Http::response([
                    'amount' => 1.0,
                    'base' => 'USD',
                    'date' => now()->toDateString(),
                    'rates' => ['EUR' => 0.85],
                ]);
        });

        app(FrankfurterProvider::class)->getLatestRates('USD', ['USD']);

        $this->assertSame(2, $attempts);
    }

    public function test_provider_rejects_invalid_rate_values(): void
    {
        config([
            'currency.anchor' => 'USD',
            'currency.provider.name' => 'frankfurter',
            'currency.provider.base_url' => 'https://provider.test',
        ]);

        Http::fake([
            'https://provider.test/*' => Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'date' => now()->toDateString(),
                'rates' => ['KWD' => -1],
            ]),
        ]);

        $this->expectException(\App\Exceptions\ExchangeRateProviderException::class);

        app(FrankfurterProvider::class)->getLatestRates('USD', ['USD', 'KWD']);
    }

    public function test_provider_rejects_excessive_rate_precision(): void
    {
        config([
            'currency.anchor' => 'USD',
            'currency.provider.name' => 'frankfurter',
            'currency.provider.base_url' => 'https://provider.test',
        ]);

        Http::fake([
            'https://provider.test/*' => Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'date' => now()->toDateString(),
                'rates' => ['KWD' => '0.12345678901'],
            ]),
        ]);

        $this->expectException(\App\Exceptions\ExchangeRateProviderException::class);

        app(FrankfurterProvider::class)->getLatestRates('USD', ['USD', 'KWD']);
    }

    public function test_provider_rejects_wrong_base(): void
    {
        config([
            'currency.anchor' => 'USD',
            'currency.provider.name' => 'frankfurter',
            'currency.provider.base_url' => 'https://provider.test',
        ]);

        Http::fake([
            'https://provider.test/*' => Http::response([
                'amount' => 1.0,
                'base' => 'EUR',
                'date' => now()->toDateString(),
                'rates' => ['USD' => 1.08],
            ]),
        ]);

        $this->expectException(\App\Exceptions\ExchangeRateProviderException::class);

        app(FrankfurterProvider::class)->getLatestRates('USD', ['USD']);
    }
}

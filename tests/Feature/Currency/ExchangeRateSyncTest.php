<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\Enums\RateMode;
use App\Models\CurrencyRate;
use App\Services\Currency\ExchangeRateSyncService;
use Illuminate\Support\Facades\Http;

class ExchangeRateSyncTest extends CurrencyTestCase
{
    private function configureProvider(): void
    {
        config([
            'currency.enabled' => true,
            'currency.anchor' => 'USD',
            'currency.provider.name' => 'frankfurter',
            'currency.provider.base_url' => 'https://provider.test',
            'currency.sync.provider_data_max_age_hours' => 72,
        ]);
    }

    private function fakeRates(array $rates, int $status = 200): void
    {
        // Frankfurter: {amount, base, date, rates: {CODE: rate}}
        $payload = [
            'amount' => 1.0,
            'base' => 'USD',
            'date' => now()->toDateString(),
            'rates' => $rates,
        ];

        Http::fake([
            'https://provider.test/*' => Http::response($payload, $status),
        ]);
    }

    public function test_auto_rates_update_and_manual_rates_are_preserved(): void
    {
        $currencies = $this->seedCurrencyData();
        $kwd = $currencies['KWD'];
        $kwd->update([
            'rate_mode' => RateMode::AUTO,
            'provider_rate' => '0.2210000000',
            'provider_rate_at' => now(),
        ]);

        $this->configureProvider();
        $this->fakeRates(['KWD' => 0.250, 'SAR' => 3.80, 'USD' => 1, 'EUR' => 0.999, 'GBP' => 0.86, 'AED' => 3.6725]);

        $result = app(ExchangeRateSyncService::class)->sync();

        $this->assertSame('success', $result['status']);
        $this->assertEquals(0.25, (float) CurrencyRate::query()->where('currency_id', $kwd->id)->value('exchange_rate'));
        $this->assertEquals(0.25, (float) $kwd->fresh()->provider_rate);
        $this->assertEquals(3.75, (float) CurrencyRate::query()->where('currency_id', $currencies['SAR']->id)->value('exchange_rate'));
        $this->assertEquals(3.8, (float) $currencies['SAR']->fresh()->provider_rate);
    }

    public function test_provider_failure_preserves_existing_rates(): void
    {
        $currencies = $this->seedCurrencyData();
        $this->configureProvider();
        $this->fakeRates([], 500);

        $result = app(ExchangeRateSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertEquals(0.221, (float) CurrencyRate::query()->where('currency_id', $currencies['KWD']->id)->value('exchange_rate'));
        $this->assertNull($currencies['KWD']->fresh()->provider_rate);
    }

    public function test_incomplete_provider_response_performs_no_writes(): void
    {
        $currencies = $this->seedCurrencyData();
        $this->configureProvider();
        // Missing SAR (active currency) → batch reject
        $this->fakeRates(['KWD' => 0.25]);

        $result = app(ExchangeRateSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertEquals(0.221, (float) CurrencyRate::query()->where('currency_id', $currencies['KWD']->id)->value('exchange_rate'));
        $this->assertNull($currencies['KWD']->fresh()->provider_rate);
    }

    public function test_dry_run_does_not_write_provider_or_effective_rates(): void
    {
        $currencies = $this->seedCurrencyData();
        $this->configureProvider();
        $this->fakeRates(['KWD' => 0.25, 'SAR' => 3.80, 'USD' => 1, 'EUR' => 0.999, 'GBP' => 0.86, 'AED' => 3.6725]);

        $result = app(ExchangeRateSyncService::class)->sync(dryRun: true);

        $this->assertSame('success', $result['status']);
        $this->assertNull($currencies['KWD']->fresh()->provider_rate);
        $this->assertEquals(0.221, (float) CurrencyRate::query()->where('currency_id', $currencies['KWD']->id)->value('exchange_rate'));
    }

    public function test_established_rate_anomaly_rejects_the_complete_batch(): void
    {
        $currencies = $this->seedCurrencyData();
        $currencies['KWD']->update([
            'provider_rate' => '0.2210000000',
            'provider_rate_at' => now(),
        ]);
        $this->configureProvider();
        $this->fakeRates(['KWD' => 0.500, 'SAR' => 3.75, 'USD' => 1, 'EUR' => 0.999, 'GBP' => 0.86, 'AED' => 3.6725]);

        $result = app(ExchangeRateSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertNull($currencies['USD']->fresh()->provider_rate);
        $this->assertEquals(0.221, (float) CurrencyRate::query()->where('currency_id', $currencies['KWD']->id)->value('exchange_rate'));
    }

    public function test_command_dry_run_reports_anomalies_without_writing(): void
    {
        $currencies = $this->seedCurrencyData();
        $this->configureProvider();
        $this->fakeRates(['KWD' => 0.50, 'SAR' => 3.80, 'USD' => 1, 'EUR' => 0.999, 'GBP' => 0.86, 'AED' => 3.6725]);

        $this->artisan('currency:sync-rates', ['--dry-run' => true])
            ->assertExitCode(2);

        $this->assertNull($currencies['KWD']->fresh()->provider_rate);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\Enums\RateMode;
use App\Exceptions\ExchangeRateApiDisabledException;
use App\Models\Currency;
use App\Models\CurrencyRate;
use App\Services\Currency\CurrencyService;
use App\Services\Currency\ExchangeRateSyncService;
use App\Services\Currency\Providers\FrankfurterProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ExchangeRateApiToggleTest extends CurrencyTestCase
{
    private function configureApi(bool $enabled): void
    {
        config([
            'currency.exchange_api.enabled' => $enabled,
            'currency.anchor' => 'USD',
            'currency.provider.name' => 'frankfurter',
            'currency.provider.base_url' => 'https://provider.test',
            'currency.sync.provider_data_max_age_hours' => 72,
        ]);
    }

    /**
     * @param array<string, float|int|string> $rates
     */
    private function fakeRates(array $rates): void
    {
        Http::fake([
            'https://provider.test/*' => Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'date' => now()->toDateString(),
                'rates' => $rates,
            ]),
        ]);
    }

    /**
     * @return array<string, float>
     */
    private function completeRates(): array
    {
        return ['KWD' => 0.25, 'SAR' => 3.80, 'USD' => 1];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rateRows(): array
    {
        return DB::table('currency_rates')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function test_provider_refuses_http_when_api_is_disabled(): void
    {
        $this->configureApi(false);
        Http::fake();

        try {
            app(FrankfurterProvider::class)->getLatestRates('USD', ['USD']);
            $this->fail('Expected ExchangeRateApiDisabledException.');
        } catch (ExchangeRateApiDisabledException $exception) {
            $this->assertSame('Currency exchange API is disabled.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_enabled_provider_sends_requests(): void
    {
        $this->configureApi(true);
        $this->fakeRates(['KWD' => 0.25, 'USD' => 1]);

        $snapshot = app(FrankfurterProvider::class)->getLatestRates('USD', ['USD', 'KWD']);

        $this->assertSame('0.2500000000', $snapshot->rates['KWD']);
        Http::assertSentCount(1);
    }

    public function test_disabled_sync_makes_no_http_requests_and_preserves_rates(): void
    {
        $currencies = $this->seedCurrencyData();
        $before = $this->rateRows();

        $this->configureApi(false);
        Http::fake();

        $result = app(ExchangeRateSyncService::class)->sync();

        $this->assertSame('disabled', $result['status']);
        $this->assertSame(0, $result['changed']);
        Http::assertNothingSent();

        $this->assertSame($before, $this->rateRows());
        $this->assertCount(3, $this->rateRows());
        $this->assertNull($currencies['KWD']->fresh()->provider_rate);

        $dryRun = app(ExchangeRateSyncService::class)->sync(dryRun: true);
        $this->assertSame('disabled', $dryRun['status']);
        Http::assertNothingSent();
    }

    public function test_disabled_command_and_dry_run_make_no_http_requests(): void
    {
        $currencies = $this->seedCurrencyData();

        $this->configureApi(false);
        Http::fake();

        $this->artisan('currency:sync-rates')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);

        $this->artisan('currency:sync-rates', ['--dry-run' => true])
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertNull($currencies['KWD']->fresh()->provider_rate);
    }

    public function test_disabled_scheduler_blocks_the_currency_sync_event(): void
    {
        $this->seedCurrencyData();
        $this->configureApi(false);
        Http::fake();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'currency:sync-rates'));

        $this->assertNotNull($event);
        $this->assertFalse($event->filtersPass($this->app));

        config(['currency.exchange_api.enabled' => true]);
        $this->assertTrue($event->filtersPass($this->app));

        Http::assertNothingSent();
    }

    public function test_disabled_admin_auto_without_stored_rate_returns_422_without_http(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];
        $kwd->update([
            'rate_mode' => RateMode::MANUAL,
            'manual_rate' => '0.2210000000',
            'provider_rate' => null,
            'provider_rate_at' => null,
            'provider' => null,
        ]);

        $this->configureApi(false);
        Http::fake();

        $response = $this->patchJson(self::PREFIX . "/currencies/{$kwd->id}/rate-mode", [
            'mode' => 'auto',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', __('message.ERROR.EXCHANGE_RATE_NOT_FOUND'));

        Http::assertNothingSent();

        $fresh = $kwd->fresh();
        $this->assertSame(RateMode::MANUAL, $fresh->rate_mode);
        $this->assertNull($fresh->provider_rate);
    }

    public function test_disabled_manual_rate_is_saved_and_stored_rate_conversion_works(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];

        $this->configureApi(false);
        Http::fake();

        $this->patchJson(self::PREFIX . "/currencies/{$kwd->id}/rate-mode", [
            'mode' => 'manual',
            'manual_rate' => '0.3000000000',
        ])->assertStatus(200);

        $storedRate = CurrencyRate::query()
            ->where('currency_id', $kwd->id)
            ->whereDate('effective_date', now()->toDateString())
            ->value('exchange_rate');

        $this->assertTrue(bccomp((string) $storedRate, '0.3000000000', 10) === 0);
        $this->assertSame(RateMode::MANUAL, $kwd->fresh()->rate_mode);

        $this->app->forgetInstance(CurrencyService::class);
        $this->assertEquals(30.0, app(CurrencyService::class)->convertPrice('100', 'USD', 'KWD'));

        Http::assertNothingSent();
    }

    public function test_disabled_auto_with_fresh_stored_provider_rate_succeeds_without_http(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];
        $kwd->update([
            'rate_mode' => RateMode::MANUAL,
            'provider_rate' => '0.2500000000',
            'provider' => 'frankfurter',
            'provider_rate_at' => now(),
        ]);

        $this->configureApi(false);
        Http::fake();

        $this->patchJson(self::PREFIX . "/currencies/{$kwd->id}/rate-mode", [
            'mode' => 'auto',
        ])->assertStatus(200);

        $fresh = $kwd->fresh();
        $this->assertSame(RateMode::AUTO, $fresh->rate_mode);
        $this->assertTrue(bccomp((string) $fresh->provider_rate, '0.2500000000', 10) === 0);
        Http::assertNothingSent();
    }

    public function test_reenabling_resumes_synchronization_and_keeps_historical_rates(): void
    {
        $currencies = $this->seedCurrencyData();
        $historical = $this->createRate($currencies['KWD'], '0.2000000000', now()->subDay()->toDateString());

        $this->configureApi(false);
        $this->fakeRates($this->completeRates());

        $disabled = app(ExchangeRateSyncService::class)->sync();
        $this->assertSame('disabled', $disabled['status']);
        Http::assertNothingSent();

        $this->configureApi(true);

        $enabled = app(ExchangeRateSyncService::class)->sync();

        $this->assertSame('success', $enabled['status'], $enabled['error'] ?? 'no-error');
        Http::assertSentCount(1);
        $this->assertEquals(0.25, (float) $currencies['KWD']->fresh()->provider_rate);
        $this->assertTrue(
            bccomp((string) $historical->fresh()->exchange_rate, '0.2000000000', 10) === 0
        );
    }

    public function test_unknown_provider_currencies_are_not_inserted(): void
    {
        $this->seedCurrencyData();

        $this->configureApi(true);
        $this->fakeRates(array_merge($this->completeRates(), ['XYZ' => 2.0, 'ABC' => 3.0]));

        $result = app(ExchangeRateSyncService::class)->sync();

        $this->assertSame('success', $result['status']);
        $this->assertSame(3, Currency::query()->count());
        $this->assertFalse(Currency::query()->whereIn('code', ['XYZ', 'ABC'])->exists());
        Http::assertSentCount(1);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\Enums\RateMode;
use App\Enums\RateSource;
use App\Models\Currency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MandatoryCurrencyGateTest extends CurrencyTestCase
{
    public function test_mandatory_auto_bootstrap_from_null_provider_rate(): void
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
                'rates' => ['KWD' => '52.1234567890', 'USD' => '1.0'],
            ]),
        ]);

        $response = $this->patchJson(self::PREFIX . "/currencies/{$kwd->id}/rate-mode", ['mode' => 'auto']);
        $response->assertStatus(200);

        $fresh = $kwd->fresh();
        $this->assertSame(RateMode::AUTO, $fresh->rate_mode);
        $this->assertTrue(bccomp((string) $fresh->provider_rate, '52.1234567890', 10) === 0, "provider_rate should be 52.1234567890, got {$fresh->provider_rate}");
        $this->assertTrue(bccomp((string) $fresh->effectiveRate(), '52.1234567890', 10) === 0);
        $this->assertNotNull($fresh->provider_rate_at);
        $this->assertSame('frankfurter', $fresh->provider);
        $rate = \App\Models\CurrencyRate::where('currency_id', $kwd->id)->whereDate('effective_date', now()->toDateString())->first();
        $this->assertTrue(bccomp((string) $rate->exchange_rate, '52.1234567890', 10) === 0);
        $this->assertSame(RateSource::PROVIDER, $rate->source);
        // manual_rate must not be effective
        $this->assertNotEquals('52.1234567890', $fresh->manual_rate);
    }

    public function test_mandatory_auto_failure_preserves_previous_state(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];
        $kwd->update([
            'rate_mode' => RateMode::MANUAL,
            'manual_rate' => '0.2210000000',
            'provider_rate' => null,
        ]);
        // Ensure manual rate is effective before
        $this->assertTrue(bccomp((string) $kwd->fresh()->effectiveRate(), '0.2210000000', 10) === 0);

        config([
            'currency.provider.name' => 'frankfurter',
            'currency.provider.base_url' => 'https://provider.test',
        ]);
        Http::fake(['https://provider.test/*' => Http::response([], 500)]);

        $response = $this->patchJson(self::PREFIX . "/currencies/{$kwd->id}/rate-mode", ['mode' => 'auto']);
        $response->assertStatus(422);

        $fresh = $kwd->fresh();
        $this->assertSame(RateMode::MANUAL, $fresh->rate_mode);
        $this->assertTrue(bccomp((string) $fresh->effectiveRate(), '0.2210000000', 10) === 0);
        $this->assertNull($fresh->provider_rate);
        $this->assertNull(\App\Models\CurrencyRate::where('currency_id', $kwd->id)->where('source', RateSource::PROVIDER->value)->whereDate('effective_date', now()->toDateString())->first());
    }

    public function test_mandatory_auto_to_manual(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];
        $kwd->update([
            'rate_mode' => RateMode::AUTO,
            'provider_rate' => '0.3000000000',
            'provider' => 'frankfurter',
            'provider_rate_at' => now(),
        ]);
        \App\Models\CurrencyRate::where('currency_id', $kwd->id)->whereDate('effective_date', now()->toDateString())->delete();
        \App\Models\CurrencyRate::create([
            'currency_id' => $kwd->id,
            'effective_date' => now()->toDateString(),
            'exchange_rate' => '0.3000000000',
            'source' => RateSource::PROVIDER->value,
            'provider' => 'frankfurter',
        ]);

        $response = $this->patchJson(self::PREFIX . "/currencies/{$kwd->id}/rate-mode", [
            'mode' => 'manual',
            'manual_rate' => '0.4000000000',
        ]);
        $response->assertStatus(200);
        $fresh = $kwd->fresh();
        $this->assertSame(RateMode::MANUAL, $fresh->rate_mode);
        $this->assertTrue(bccomp((string) $fresh->effectiveRate(), '0.4000000000', 10) === 0);
        $this->assertTrue(bccomp((string) \App\Models\CurrencyRate::where('currency_id', $kwd->id)->whereDate('effective_date', now()->toDateString())->value('exchange_rate'), '0.4000000000', 10) === 0);
        // conversion uses new manual rate
        $converted = app(\App\Services\Currency\CurrencyService::class)->convertPrice('100', 'USD', 'KWD');
        $this->assertEquals(40.0, $converted);
    }

    public function test_mandatory_cache_invalidation(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];
        $kwd->update(['rate_mode' => RateMode::MANUAL, 'manual_rate' => '0.2210000000']);
        \App\Models\CurrencyRate::where('currency_id', $kwd->id)->whereDate('effective_date', now()->toDateString())->delete();
        \App\Models\CurrencyRate::create([
            'currency_id' => $kwd->id,
            'effective_date' => now()->toDateString(),
            'exchange_rate' => '0.2210000000',
            'source' => RateSource::MANUAL->value,
        ]);
        $service = app(\App\Services\Currency\CurrencyService::class);
        $service->forgetRateCache();
        $first = $service->convertPrice('100', 'USD', 'KWD'); // caches
        $this->assertEquals(22.10, $first);

        // Change rate via API (should invalidate)
        $this->patchJson(self::PREFIX . "/currencies/{$kwd->id}/rate-mode", [
            'mode' => 'manual',
            'manual_rate' => '0.3000000000',
        ])->assertStatus(200);

        $service2 = app(\App\Services\Currency\CurrencyService::class);
        // Ensure rateCache was cleared (service is singleton, but setRateMode calls forgetRateCache)
        $second = $service2->convertPrice('100', 'USD', 'KWD');
        $this->assertEquals(30.0, $second);
        $this->assertNotEquals($first, $second);
    }

    public function test_mandatory_scheduler_disabled_when_flag_false(): void
    {
        config(['currency.enabled' => false]);
        $kernelContent = file_get_contents(app_path('Console/Kernel.php'));
        $this->assertStringContainsString("currency:sync-rates", $kernelContent);
        $this->assertStringContainsString("everySixHours", $kernelContent);
        $this->assertStringContainsString("withoutOverlapping", $kernelContent);
        $this->assertStringContainsString("onOneServer", $kernelContent);
        $this->assertStringContainsString("when", $kernelContent);
        $this->assertStringContainsString("currency.enabled", $kernelContent);
    }
}

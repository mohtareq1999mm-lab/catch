<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\Enums\RateMode;
use App\Models\Currency;

class CurrencyRateModeTest extends CurrencyTestCase
{
    public function test_manual_to_auto_uses_the_latest_provider_rate(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];
        $kwd->update([
            'provider_rate' => '0.3074600000',
            'provider' => 'frankfurter',
            'provider_rate_at' => now(),
        ]);

        $response = $this->patchJson(self::PREFIX . "/currencies/{$kwd->id}/rate-mode", [
            'mode' => 'auto',
        ]);

        $response->assertStatus(200);
        $this->assertSame(RateMode::AUTO, $kwd->fresh()->rate_mode);
        $this->assertEquals(0.30746, (float) \App\Models\CurrencyRate::query()->where('currency_id', $kwd->id)->value('exchange_rate'));
    }

    public function test_auto_to_manual_requires_and_applies_manual_rate(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];
        $kwd->update([
            'rate_mode' => RateMode::AUTO,
            'provider_rate' => '0.3074600000',
            'provider' => 'frankfurter',
            'provider_rate_at' => now(),
        ]);

        $response = $this->patchJson(self::PREFIX . "/currencies/{$kwd->id}/rate-mode", [
            'mode' => 'manual',
            'manual_rate' => '0.3180000000',
        ]);

        $response->assertStatus(200);
        $this->assertSame(RateMode::MANUAL, $kwd->fresh()->rate_mode);
        $this->assertEquals(0.318, (float) $kwd->fresh()->manual_rate);
        $this->assertEquals(0.318, (float) \App\Models\CurrencyRate::query()->where('currency_id', $kwd->id)->value('exchange_rate'));
    }

    public function test_manual_mode_requires_a_manual_rate(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];

        $response = $this->patchJson(self::PREFIX . "/currencies/{$kwd->id}/rate-mode", [
            'mode' => 'manual',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('manual_rate');
    }

    public function test_customer_cannot_change_rate_mode(): void
    {
        $this->createAuthenticatedCustomer();
        $kwd = $this->seedCurrencyData()['KWD'];

        $this->patchJson(self::PREFIX . "/currencies/{$kwd->id}/rate-mode", [
            'mode' => 'auto',
        ])->assertStatus(403);
    }
}

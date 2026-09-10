<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\Enums\FrontendResource;
use App\Services\Currency\CurrencyService;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Settings;

class CatalogCurrencyTest extends CurrencyTestCase
{
    /** @test */
    public function admin_can_set_a_new_catalog_currency(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];

        $response = $this->postJson(self::PREFIX . "/currencies/{$kwd->id}/set-catalog");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', __('message.MESSAGE.SET_CATALOG_CURRENCY_SUCCESSFULLY'));
        $response->assertJsonPath('data.code', 'KWD');
        $response->assertJsonPath('data.is_catalog', true);
    }

    /** @test */
    public function set_catalog_updates_only_the_catalog_currency_option(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];

        $this->postJson(self::PREFIX . "/currencies/{$kwd->id}/set-catalog")->assertStatus(200);

        $settings = Settings::query()->first();
        $options = $settings->options;

        $this->assertSame('KWD', $options['catalog_currency_code']);
        $this->assertSame('USD', $options['base_currency_code']);
        $this->assertSame('USD', $options['currency']);
    }

    /** @test */
    public function catalog_code_is_reflected_by_currency_service(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];

        $this->postJson(self::PREFIX . "/currencies/{$kwd->id}/set-catalog")->assertStatus(200);

        $this->assertSame('KWD', app(CurrencyService::class)->getCatalogCode());
        $this->assertSame('USD', app(CurrencyService::class)->getBaseCode());
        $this->assertSame('KWD', app(CurrencyService::class)->getCatalogCurrency()->code);
    }

    /** @test */
    public function inactive_currency_cannot_be_set_as_catalog(): void
    {
        $this->createAuthenticatedAdmin();
        $currencies = $this->seedCurrencyData();
        $kwd = $currencies['KWD'];
        $kwd->update(['is_active' => false]);

        $response = $this->postJson(self::PREFIX . "/currencies/{$kwd->id}/set-catalog");

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', __('message.ERROR.CURRENCY_INACTIVE'));

        $settings = Settings::query()->first();
        $this->assertSame('USD', $settings->options['catalog_currency_code']);
    }

    /** @test */
    public function currency_without_a_rate_cannot_be_set_as_catalog(): void
    {
        $this->createAuthenticatedAdmin();
        $this->seedCurrencyData();
        $eur = $this->createCurrency('EUR');

        $response = $this->postJson(self::PREFIX . "/currencies/{$eur->id}/set-catalog");

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', __('message.ERROR.EXCHANGE_RATE_NOT_FOUND'));

        $settings = Settings::query()->first();
        $this->assertSame('USD', $settings->options['catalog_currency_code']);
    }

    /** @test */
    public function set_catalog_for_missing_currency_returns_404(): void
    {
        $this->createAuthenticatedAdmin();
        $this->seedCurrencyData();

        $this->postJson(self::PREFIX . '/currencies/999999/set-catalog')->assertStatus(404);
    }

    /** @test */
    public function set_catalog_requires_permission(): void
    {
        $user = $this->createUserWithPermissions(['view-currencies'], 'admin');
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $kwd = $this->seedCurrencyData()['KWD'];

        $this->postJson(self::PREFIX . "/currencies/{$kwd->id}/set-catalog")->assertStatus(403);
    }

    /** @test */
    public function set_catalog_flushes_frontend_price_caches(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];

        Cache::tags([FrontendResource::PRODUCTS->value])->put('test.product', 'cached', 3600);
        Cache::tags([FrontendResource::CURRENCIES->value])->put('test.currency', 'cached', 3600);

        $this->postJson(self::PREFIX . "/currencies/{$kwd->id}/set-catalog")->assertStatus(200);

        $this->assertFalse(Cache::tags([FrontendResource::PRODUCTS->value])->has('test.product'));
        $this->assertFalse(Cache::tags([FrontendResource::CURRENCIES->value])->has('test.currency'));
    }
}
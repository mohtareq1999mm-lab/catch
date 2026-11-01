<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Services\Currency\CurrencyService;
use App\Services\Payment\GatewaySettingsService;
use Carbon\Carbon;
use Marvel\Database\Models\Settings;
use Tests\Feature\Currency\CurrencyTestCase;

class PublicPaymentGatewaysTest extends CurrencyTestCase
{
    private const PUBLIC_PREFIX = '/api/v1/general/payment-gateways';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCurrencyData();

        // Hermetic baseline regardless of the developer .env.
        config(['payment.gateways.myfatoorah.api_key' => 'test-key']);
        config(['payment.gateways.stripe.secret_key' => null]);
        config(['payment.gateways.stripe.webhook_secret' => null]);
        config(['payment.gateways.paypal.client_secret' => null]);
        // Currency lists are env-backed: pin the ones these tests reason
        // about so a developer .env cannot narrow the tap list and skew the
        // available/on-catalog expectations.
        config(['payment.gateways.tap.supported_currencies' => ['AED', 'KWD', 'USD']]);
    }

    /** @test */
    public function endpoint_is_public_and_matches_contract_envelope(): void
    {
        $response = $this->getJson(self::PUBLIC_PREFIX);

        $response->assertOk()->assertJson(['success' => true, 'status' => 200]);
        $response->assertJsonPath('message', 'Payment options fetched successfully.');
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'gateways', 'payment_methods', 'fast_shipping',
            ],
        ]);
    }

    /** @test */
    public function gateways_expose_only_public_fields_and_no_secrets(): void
    {
        config(['payment.gateways.myfatoorah.supported_currencies' => ['KWD', 'SAR']]);
        // Canary values: fail loudly if a secret value ever reaches the payload,
        // even under a renamed key.
        config(['payment.gateways.myfatoorah.api_key' => 'CANARY-MYFATOORAH-SECRET']);
        config(['payment.gateways.stripe.secret_key' => 'CANARY-STRIPE-SECRET']);
        config(['payment.gateways.paypal.client_secret' => 'CANARY-PAYPAL-SECRET']);

        $response = $this->getJson(self::PUBLIC_PREFIX);

        $response->assertOk();
        $gateways = $response->json('data.gateways');
        $this->assertNotEmpty($gateways);

        foreach ($gateways as $row) {
            $this->assertEquals(
                ['code', 'display_name', 'enabled', 'available', 'supported_currencies', 'supports_catalog_currency'],
                array_keys($row),
                'Public gateway row must not gain/lose fields silently.'
            );
            $this->assertIsBool($row['enabled']);
            $this->assertIsBool($row['available']);
            $this->assertIsBool($row['supports_catalog_currency']);
        }

        $raw = strtolower($response->getContent());
        foreach (['secret_key', 'client_secret', 'api_key', 'webhook_secret', 'webhook_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $this->assertStringNotContainsString('canary-myfatoorah-secret', $raw);
        $this->assertStringNotContainsString('canary-stripe-secret', $raw);
        $this->assertStringNotContainsString('canary-paypal-secret', $raw);
        // enabled/available are now public BY DESIGN (§6 availability
        // exposure); configured/sort_order stay internal and the secret
        // canaries must never leak under any key.
        foreach (['configured', 'sort_order'] as $internal) {
            $this->assertArrayNotHasKey($internal, $gateways[0]);
        }
    }

    /** @test */
    public function supports_catalog_currency_flag_follows_catalog_currency(): void
    {
        // Catalog currency is USD via seedCurrencyData().
        config(['payment.gateways.myfatoorah.supported_currencies' => ['USD', 'KWD']]);
        config(['payment.gateways.stripe.supported_currencies' => ['KWD']]);

        $response = $this->getJson(self::PUBLIC_PREFIX);

        $response->assertOk();
        $byCode = collect($response->json('data.gateways'))->keyBy('code');

        $this->assertTrue($byCode['myfatoorah']['supports_catalog_currency']);
        $this->assertFalse($byCode['stripe']['supports_catalog_currency']);
    }

    /** @test */
    public function gateway_order_matches_admin_view_and_lists_disabled_gateways(): void
    {
        // A disabled gateway is still LISTED but reports enabled=false and
        // available=false so the frontend can hide it; checkout (HTTP 422)
        // stays the single authority at submit time.
        config(['payment.gateways.myfatoorah.enabled' => false]);

        $response = $this->getJson(self::PUBLIC_PREFIX);

        $response->assertOk();
        $publicCodes = array_column($response->json('data.gateways'), 'code');
        $adminCodes = array_column(app(GatewaySettingsService::class)->getAdminView(), 'code');

        $this->assertSame($adminCodes, $publicCodes);
        $this->assertContains('myfatoorah', $publicCodes);
    }

    /** @test */
    public function payment_methods_are_fixed_and_localized(): void
    {
        $response = $this->getJson(self::PUBLIC_PREFIX);

        $response->assertOk();
        $this->assertSame(
            [
                ['code' => 'online', 'display_name' => 'Online payment'],
                ['code' => 'cod', 'display_name' => 'Cash on delivery'],
                ['code' => 'pay_at_cashier', 'display_name' => 'Pay at cashier'],
            ],
            $response->json('data.payment_methods')
        );

        app()->setLocale('ar');
        $arabic = $this->getJson(self::PUBLIC_PREFIX, ['lang' => 'ar'])->json('data.payment_methods');
        $this->assertSame('دفع إلكتروني', $arabic[0]['display_name']);
        $this->assertSame('الدفع عند الاستلام', $arabic[1]['display_name']);
        $this->assertSame('الدفع عند الكاشير', $arabic[2]['display_name']);
    }

    /** @test */
    public function fast_shipping_block_matches_status_endpoint(): void
    {
        Carbon::setTestNow('2026-09-27 10:00:00');

        try {
            $response = $this->getJson(self::PUBLIC_PREFIX);
            $status = $this->getJson('/api/v1/general/fast-shipping/status');

            $response->assertOk();
            $status->assertOk();
            $this->assertSame($status->json('data'), $response->json('data.fast_shipping'));
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @test */
    public function unknown_lang_header_falls_back_to_english(): void
    {
        $response = $this->getJson(self::PUBLIC_PREFIX, ['lang' => 'xx']);

        $response->assertOk();
        $this->assertSame('Online payment', $response->json('data.payment_methods.0.display_name'));
    }

    /** @test */
    public function empty_gateway_registry_returns_empty_list(): void
    {
        config(['payment.gateways' => []]);

        $response = $this->getJson(self::PUBLIC_PREFIX);

        $response->assertOk();
        $this->assertSame([], $response->json('data.gateways'));
    }

    /** @test */
    public function missing_settings_row_degrades_gracefully(): void
    {
        // DELETE (not TRUNCATE — TRUNCATE is DDL and would implicitly commit
        // the test transaction on MySQL) every settings row: catalog currency
        // falls back to config and fast shipping reports unavailable.
        Settings::query()->delete();
        $this->app->forgetInstance(CurrencyService::class);

        $response = $this->getJson(self::PUBLIC_PREFIX);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertNotEmpty($response->json('data.gateways'));
        $this->assertFalse($response->json('data.fast_shipping.enabled'));
    }

    /**
     * Write a settings.options['payment_gateways'][code].enabled override
     * exactly the way the admin endpoint persists one. (The request layer
     * never accepts null for enabled, so there is no clear path here.)
     */
    private function setGatewayEnabled(string $code, bool $enabled): void
    {
        $settings = Settings::query()->first() ?? $this->createSettings();
        $options = is_array($settings->options) ? $settings->options : [];
        $gateways = is_array($options['payment_gateways'] ?? null) ? $options['payment_gateways'] : [];

        $gateways[$code] = array_merge($gateways[$code] ?? [], ['enabled' => $enabled]);

        $options['payment_gateways'] = $gateways;
        $settings->options = $options;
        $settings->save();
    }

    /** @test */
    public function admin_enabled_gateway_configured_and_currency_supported_is_available(): void
    {
        // Tap supports the USD catalog; only the admin toggle is missing.
        config(['payment.gateways.tap.secret_key' => 'test-tap-secret']);
        $this->setGatewayEnabled('tap', true);

        $byCode = collect($this->getJson(self::PUBLIC_PREFIX)->json('data.gateways'))->keyBy('code');

        $this->assertTrue($byCode['tap']['enabled']);
        $this->assertTrue($byCode['tap']['available']);
    }

    /** @test */
    public function site_settings_disable_reflects_enabled_false_and_available_false(): void
    {
        config(['payment.gateways.tap.secret_key' => 'test-tap-secret']);
        $this->setGatewayEnabled('tap', true);
        $this->setGatewayEnabled('tap', false);

        $byCode = collect($this->getJson(self::PUBLIC_PREFIX)->json('data.gateways'))->keyBy('code');

        // Still listed (frontend may grey it out) but flagged unavailable.
        $this->assertContains('tap', array_keys($byCode->all()));
        $this->assertFalse($byCode['tap']['enabled']);
        $this->assertFalse($byCode['tap']['available']);
    }

    /** @test */
    public function enabled_gateway_without_credentials_stays_unavailable(): void
    {
        config(['payment.gateways.myfatoorah.supported_currencies' => ['KWD', 'USD']]);
        config(['payment.gateways.myfatoorah.api_key' => null]);

        $byCode = collect($this->getJson(self::PUBLIC_PREFIX)->json('data.gateways'))->keyBy('code');

        $this->assertTrue($byCode['myfatoorah']['enabled']);
        $this->assertTrue($byCode['myfatoorah']['supports_catalog_currency']);
        $this->assertFalse($byCode['myfatoorah']['available']);
    }

    /** @test */
    public function enabled_configured_gateway_outside_catalog_currency_stays_unavailable(): void
    {
        config(['payment.gateways.myfatoorah.supported_currencies' => ['KWD']]);

        $byCode = collect($this->getJson(self::PUBLIC_PREFIX)->json('data.gateways'))->keyBy('code');

        $this->assertTrue($byCode['myfatoorah']['enabled']);
        // configured itself stays internal; prove its TRUE state via the
        // admin view so the ONLY public false operand is the currency.
        $this->assertTrue(collect(app(GatewaySettingsService::class)->getAdminView())->firstWhere('code', 'myfatoorah')['configured']);
        $this->assertFalse($byCode['myfatoorah']['supports_catalog_currency']);
        $this->assertFalse($byCode['myfatoorah']['available']);
    }
}

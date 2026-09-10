<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\DTOs\CheckoutTotals;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Currency\UserCurrencyPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;

class CurrencySelectionEnabledTest extends CurrencyTestCase
{
    private const SETTINGS_PERMISSIONS = [
        'view-settings',
        'update-settings',
    ];

    private function createSettingsAdmin(): User
    {
        return $this->createUserWithPermissions(self::SETTINGS_PERMISSIONS, 'admin');
    }

    private function setCurrencySelectionEnabled(bool $enabled): void
    {
        $settings = Settings::first();

        if (!$settings) {
            $this->createSettings();
            $settings = Settings::first();
        }

        $options = $settings->options ?? [];
        $options['currency_selection_enabled'] = $enabled;
        $settings->options = $options;
        $settings->save();

        $this->app->forgetInstance(CurrencyService::class);
    }

    private function createSettingsWithoutSelectionFlag(): Settings
    {
        return Settings::create([
            'site_name' => ['en' => 'Test Site', 'ar' => 'موقع تجريبي'],
            'options' => [
                'currency' => 'USD',
                'base_currency_code' => 'USD',
                'catalog_currency_code' => 'USD',
            ],
            'minimum_order_amount' => 0,
        ]);
    }

    /** @test */
    public function is_currency_selection_enabled_defaults_to_false_when_not_configured(): void
    {
        $this->createSettingsWithoutSelectionFlag();

        $this->assertFalse(app(CurrencyService::class)->isCurrencySelectionEnabled());
    }

    /** @test */
    public function is_currency_selection_enabled_returns_true_when_enabled(): void
    {
        $this->seedCurrencyData();
        $this->setCurrencySelectionEnabled(true);

        $this->assertTrue(app(CurrencyService::class)->isCurrencySelectionEnabled());
    }

    /** @test */
    public function is_currency_selection_enabled_returns_false_when_disabled(): void
    {
        $this->seedCurrencyData();
        $this->setCurrencySelectionEnabled(false);

        $this->assertFalse(app(CurrencyService::class)->isCurrencySelectionEnabled());
    }

    /** @test */
    public function effective_currency_is_catalog_when_selection_disabled_even_with_user_preference(): void
    {
        $this->seedCurrencyData();
        $this->setCurrencySelectionEnabled(false);

        $user = $this->createCustomerWithCurrencyPreference('SAR');

        $this->assertSame('SAR', app(UserCurrencyPreferenceService::class)->getUserPreference($user));
        $this->assertSame('USD', app(CurrencyService::class)->getEffectiveCode($user));
    }

    /** @test */
    public function effective_currency_ignores_guest_cookie_when_selection_disabled(): void
    {
        $this->seedCurrencyData();
        $this->setCurrencySelectionEnabled(false);

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => 'KWD']);
        $this->app->instance('request', $request);
        $this->app->forgetInstance(CurrencyService::class);

        $this->assertSame('USD', app(CurrencyService::class)->getEffectiveCode());
    }

    /** @test */
    public function effective_currency_prefers_user_preference_when_selection_enabled(): void
    {
        $this->seedCurrencyData();
        $this->setCurrencySelectionEnabled(true);

        $user = $this->createCustomerWithCurrencyPreference('SAR');

        $this->assertSame('SAR', app(CurrencyService::class)->getEffectiveCode($user));
    }

    /** @test */
    public function effective_currency_uses_guest_cookie_when_selection_enabled(): void
    {
        $this->seedCurrencyData();
        $this->setCurrencySelectionEnabled(true);

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => 'KWD']);
        $this->app->instance('request', $request);
        $this->app->forgetInstance(CurrencyService::class);

        $this->assertSame('KWD', app(CurrencyService::class)->getEffectiveCode());
    }

    /** @test */
    public function effective_currency_falls_back_to_catalog_when_selection_enabled(): void
    {
        $this->seedCurrencyData();
        $this->setCurrencySelectionEnabled(true);

        $this->assertSame('USD', app(CurrencyService::class)->getEffectiveCode());
    }

    /** @test */
    public function admin_can_read_the_currency_selection_setting(): void
    {
        $this->createSettingsWithoutSelectionFlag();
        Sanctum::actingAs($this->createSettingsAdmin());

        $response = $this->getJson(self::PREFIX . '/settings');

        $response->assertOk();
        $this->assertFalse($response->json('data.currency_selection_enabled'));
    }

    /** @test */
    public function admin_can_enable_currency_selection(): void
    {
        $this->seedCurrencyData();
        $this->setCurrencySelectionEnabled(false);
        Sanctum::actingAs($this->createSettingsAdmin());

        $response = $this->putJson(self::PREFIX . '/settings', [
            'currency_selection_enabled' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertTrue($response->json('data.currency_selection_enabled'));
        $this->assertTrue(Settings::first()->options['currency_selection_enabled']);
    }

    /** @test */
    public function admin_can_disable_currency_selection(): void
    {
        $this->seedCurrencyData();
        Sanctum::actingAs($this->createSettingsAdmin());

        $response = $this->putJson(self::PREFIX . '/settings', [
            'currency_selection_enabled' => false,
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('data.currency_selection_enabled'));
        $this->assertFalse(Settings::first()->options['currency_selection_enabled']);
    }

    /** @test */
    public function admin_update_with_invalid_boolean_is_rejected(): void
    {
        $this->seedCurrencyData();
        Sanctum::actingAs($this->createSettingsAdmin());

        $response = $this->putJson(self::PREFIX . '/settings', [
            'currency_selection_enabled' => 'not-a-boolean',
        ]);

        $response->assertStatus(422);
        $this->assertTrue(Settings::first()->options['currency_selection_enabled']);
    }

    /** @test */
    public function admin_update_with_invalid_numeric_boolean_is_rejected(): void
    {
        $this->seedCurrencyData();
        Sanctum::actingAs($this->createSettingsAdmin());

        $response = $this->putJson(self::PREFIX . '/settings', [
            'currency_selection_enabled' => 2,
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function settings_cache_is_cleared_after_updating_currency_selection(): void
    {
        $this->seedCurrencyData();
        Sanctum::actingAs($this->createSettingsAdmin());

        $this->getJson(self::PREFIX . '/settings')->assertOk();

        $this->putJson(self::PREFIX . '/settings', [
            'currency_selection_enabled' => false,
        ])->assertOk();

        $response = $this->getJson(self::PREFIX . '/settings');
        $response->assertOk();
        $this->assertFalse($response->json('data.currency_selection_enabled'));
    }

    /** @test */
    public function toggling_the_setting_does_not_alter_base_or_catalog_codes(): void
    {
        $this->seedCurrencyData();

        app(CurrencyService::class)->setBaseCurrency(\App\Models\Currency::query()->where('code', 'KWD')->firstOrFail());
        $this->setCurrencySelectionEnabled(false);

        $options = Settings::first()->options;
        $this->assertSame('KWD', $options['base_currency_code']);
        $this->assertSame('USD', $options['catalog_currency_code']);
    }

    /** @test */
    public function disabling_currency_selection_preserves_user_preferences(): void
    {
        $this->seedCurrencyData();

        $user = $this->createCustomerWithCurrencyPreference('SAR');
        $this->assertSame('SAR', app(UserCurrencyPreferenceService::class)->getUserPreference($user));

        $this->setCurrencySelectionEnabled(false);

        $this->assertSame('SAR', app(UserCurrencyPreferenceService::class)->getUserPreference($user));
        $this->assertSame('USD', app(CurrencyService::class)->getEffectiveCode($user));
    }

    /** @test */
    public function disabling_currency_selection_does_not_alter_existing_orders(): void
    {
        Event::fake();
        $this->seedCurrencyData();
        $this->setCurrencySelectionEnabled(true);

        $cart = Cart::create([
            'user_id' => $this->createCustomer()->id,
            'status' => 'active',
            'total_price' => 100.0,
        ]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $cart->user_id,
                'name' => 'Currency Customer',
                'user_phone' => '01000000000',
                'user_email' => 'currency@example.com',
                'address' => '123 Currency Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals(
                subtotal: 100.0,
                promotionDiscount: 0,
                couponDiscount: 0,
                finalTotal: 100.0,
            ),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);
        $this->assertSame('USD', $order->currency_code);

        $this->setCurrencySelectionEnabled(false);

        $order->refresh();
        $this->assertSame('USD', $order->currency_code);
        $this->assertSame('USD', $order->base_currency_code);
        $this->assertSame('USD', $order->catalog_currency_code);
        $this->assertSame(100.0, $order->total_price);
        $this->assertSame(100.0, $order->converted_total_price);
    }
}

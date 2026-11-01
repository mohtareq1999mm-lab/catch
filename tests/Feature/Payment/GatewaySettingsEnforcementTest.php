<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\CheckoutTotals;
use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Gateway\TabbyGateway;
use App\Services\Gateway\TamaraGateway;
use App\Services\Gateway\TapGateway;
use App\Services\Payment\GatewaySettingsService;
use App\Services\Payment\PaymentCheckoutHandler;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentGatewayRegistry;
use Illuminate\Http\Request;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Settings;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * §5/§8/§9 per-gateway enforcement for the gateways the Site Settings admin
 * API can now toggle (tap, tabby, tamara + default-state stripe). The
 * gateway-agnostic 422 machinery is already proved end-to-end for
 * myfatoorah in GatewayRegistryTest and GatewayDisableTest.
 *
 * Gate-order facts exercised here (PaymentGatewayRegistry::canInitiate):
 *   enabled is checked FIRST → 'disabled' regardless of currency/method;
 *   then configured → 'misconfigured';
 *   then method, then currency.
 * The verify path (canVerify/resolve/factory make) deliberately ignores
 * the enabled flag so in-flight payments still complete after a disable —
 * never add an enabled check there (see PaymentGatewayRegistry docblock).
 */
class GatewaySettingsEnforcementTest extends CurrencyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCurrencyData();

        // Hermetic baseline regardless of the developer .env.
        config(['payment.gateways.myfatoorah.api_key' => 'test-key']);
        config(['payment.gateways.tap.secret_key' => null]);
        config(['payment.gateways.tabby.secret_key' => null]);
        config(['payment.gateways.tamara.api_token' => null]);
        config(['payment.gateways.tamara.notification_token' => null]);
        config(['payment.gateways.stripe.enabled' => false]);
        config(['payment.gateways.stripe.secret_key' => null]);
        // Currency lists are env-backed: pin the ones these tests reason
        // about so a developer .env cannot flip expected gate reasons.
        config(['payment.gateways.tap.supported_currencies' => ['AED', 'KWD', 'USD']]);
        config(['payment.gateways.tabby.supported_currencies' => ['AED', 'SAR']]);
        config(['payment.gateways.tamara.supported_currencies' => ['SAR', 'AED', 'BHD', 'KWD', 'OMR']]);
    }

    private function makeOrderForCustomer(object $customer, float $subtotal = 100.0)
    {
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Settings Enforcement Customer',
                'user_phone' => '01000000001',
                'user_email' => $customer->email,
                'address' => '123 Enforcement Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);

        return $order;
    }

    private function checkoutRequest(object $customer): Request
    {
        $request = Request::create('/api/v1/checkout', 'POST');
        $request->setUserResolver(fn () => $customer);

        return $request;
    }

    /**
     * Write a settings.options['payment_gateways'][code].enabled override
     * exactly the way the admin endpoint persists one.
     */
    private function setSettingsEnabled(string $code, bool $enabled): void
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
    public function disabling_each_mena_gateway_rejects_handler_initiation_with_no_transaction(): void
    {
        // Mirror of GatewayRegistryTest::disabled_myfatoorah_blocks_initiate...
        // for tap, tabby, tamara: settings-driven disable → handler 422 with
        // the gateway-unavailable message and zero transactions. canInitiate
        // gates enabled FIRST, so the KWD catalog (unsupported by tabby) is
        // irrelevant here — the reason must still be 'disabled'.
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());

        foreach (['tap', 'tabby', 'tamara'] as $code) {
            $customer = $this->createCustomer();
            $order = $this->makeOrderForCustomer($customer);
            $this->assertSame('KWD', $order->fresh()->currency_code, "[$code]");

            $this->setSettingsEnabled($code, false);
            $this->assertFalse(app(GatewaySettingsService::class)->isEnabled($code), "[$code]");

            $gate = app(PaymentGatewayRegistry::class)->canInitiate($code, 'online', 'KWD');
            $this->assertFalse($gate['ok'], "[$code]");
            $this->assertSame('disabled', $gate['reason'], "[$code]");

            $response = app(PaymentCheckoutHandler::class)->handleOnlinePayment(
                $this->checkoutRequest($customer),
                $order->fresh(),
                (float) $order->total_price,
                $code,
                'https://example.test/cb',
                'https://example.test/er',
            );

            $this->assertSame(422, $response->getStatusCode(), "[$code]");
            $this->assertStringContainsString(
                __('message.ERROR.PAYMENT_GATEWAY_UNAVAILABLE'),
                (string) $response->getContent(),
                "[$code]"
            );
        }

        $this->assertDatabaseCount('transactions', 0);
    }

    /** @test */
    public function stripe_cannot_start_when_disabled_and_stays_rejected_when_unconfigured(): void
    {
        // Disabled site setting wins over valid credentials (§5 rule 1).
        config([
            'payment.gateways.stripe.enabled' => false,
            'payment.gateways.stripe.secret_key' => 'test-sk',
        ]);
        $gate = app(PaymentGatewayRegistry::class)->canInitiate('stripe', 'online', 'USD');
        $this->assertFalse($gate['ok']);
        $this->assertSame('disabled', $gate['reason']);

        // Enabled but no credentials → still rejected (§5 rule 2).
        config([
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.secret_key' => null,
        ]);
        $gate = app(PaymentGatewayRegistry::class)->canInitiate('stripe', 'online', 'USD');
        $this->assertFalse($gate['ok']);
        $this->assertSame('misconfigured', $gate['reason']);
    }

    /** @test */
    public function enabled_but_unconfigured_mena_gateways_reject_initiation(): void
    {
        foreach (['tap', 'tabby', 'tamara'] as $code) {
            $this->setSettingsEnabled($code, true);

            $gate = app(PaymentGatewayRegistry::class)->canInitiate($code, 'online', 'KWD');
            $this->assertFalse($gate['ok'], "[$code]");
            $this->assertSame('misconfigured', $gate['reason'], "[$code]");
        }
    }

    /** @test */
    public function enabled_and_configured_gateways_pass_the_registry_gate_when_currency_supported(): void
    {
        config(['payment.gateways.tap.secret_key' => 'test-tap-secret']);
        config(['payment.gateways.tabby.secret_key' => 'test-tabby-secret']);
        config(['payment.gateways.tamara.api_token' => 'test-tamara-token']);
        config(['payment.gateways.tamara.notification_token' => 'test-tamara-notify']);

        $this->setSettingsEnabled('tap', true);
        $this->setSettingsEnabled('tabby', true);
        $this->setSettingsEnabled('tamara', true);

        $registry = app(PaymentGatewayRegistry::class);

        // Tap supports KWD; tamara's enum covers KWD; tabby does not — SAR
        // is its supported pick (seeded by seedCurrencyData()).
        $this->assertSame('ok', $registry->canInitiate('tap', 'online', 'KWD')['reason']);
        $this->assertSame('ok', $registry->canInitiate('tamara', 'online', 'KWD')['reason']);
        $this->assertSame('ok', $registry->canInitiate('tabby', 'online', 'SAR')['reason']);
        $this->assertSame('currency_unsupported', $registry->canInitiate('tabby', 'online', 'KWD')['reason']);

        // Toggling back off must re-block without touching config.
        foreach (['tap', 'tabby', 'tamara'] as $code) {
            $this->setSettingsEnabled($code, false);
            $gate = $registry->canInitiate($code, 'online', 'KWD');
            $this->assertFalse($gate['ok'], "[$code]");
            $this->assertSame('disabled', $gate['reason'], "[$code]");
        }
    }

    /** @test */
    public function verify_path_keeps_working_while_disabled(): void
    {
        // §9: verify/capture/webhook paths never consult the enabled flag.
        // Every verify entrypoint resolves the adapter through the registry;
        // resolve/canVerify/factory make must keep working for a disabled
        // gateway so in-flight payments after a mid-payment disable (or any
        // simply-disabled gateway) can still complete.
        $expected = [
            'tap' => TapGateway::class,
            'tabby' => TabbyGateway::class,
            'tamara' => TamaraGateway::class,
        ];

        $registry = app(PaymentGatewayRegistry::class);
        $factory = app(PaymentGatewayFactory::class);

        foreach ($expected as $code => $class) {
            $this->setSettingsEnabled($code, false);
            $this->assertFalse($registry->canInitiate($code, 'online', 'KWD')['ok'], "[$code]");

            $this->assertTrue($registry->canVerify($code), "[$code] must stay verifiable");
            $this->assertInstanceOf($class, $registry->resolve($code), "[$code]");
            $this->assertInstanceOf($class, $factory->make($code), "[$code]");
        }
    }
}

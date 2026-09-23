<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\CheckoutTotals;
use App\Exceptions\UnsupportedGatewayException;
use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Gateway\MyFatoorahGateway;
use App\Services\Payment\GatewaySettingsService;
use App\Services\Payment\PaymentCheckoutHandler;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentGatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Settings;
use Tests\Feature\Currency\CurrencyTestCase;

class GatewayRegistryTest extends CurrencyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCurrencyData();

        // Hermetic baseline: configured key regardless of the developer .env.
        config(['payment.gateways.myfatoorah.api_key' => 'test-key']);
    }

    private function makeOrderForCustomer(object $customer, float $subtotal = 100.0): Order
    {
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Registry Customer',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 Registry Street',
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
     * Disable myfatoorah via the settings.options override when the table
     * exists, otherwise fall back to a config override.
     */
    private function disableMyfatoorahViaSettings(): void
    {
        try {
            if (!Schema::hasTable('settings')) {
                throw new \RuntimeException('settings table missing');
            }

            $settings = Settings::query()->first() ?? $this->createSettings();
            $options = is_array($settings->options) ? $settings->options : [];
            $gateways = $options['payment_gateways'] ?? [];
            if (!is_array($gateways)) {
                $gateways = [];
            }
            $gateways['myfatoorah'] = array_merge($gateways['myfatoorah'] ?? [], ['enabled' => false]);
            $options['payment_gateways'] = $gateways;
            $settings->options = $options;
            $settings->save();
        } catch (\Throwable $e) {
            config(['payment.gateways.myfatoorah.enabled' => false]);
        }
    }

    /** @test */
    public function unknown_code_resolve_throws(): void
    {
        $this->expectException(UnsupportedGatewayException::class);

        app(PaymentGatewayRegistry::class)->resolve('no-such-gateway');
    }

    /** @test */
    public function factory_delegates_to_registry_for_known_gateway(): void
    {
        $gateway = app(PaymentGatewayFactory::class)->make('myfatoorah');

        $this->assertInstanceOf(MyFatoorahGateway::class, $gateway);
        $this->assertSame('myfatoorah', $gateway->code());
    }

    /** @test */
    public function known_codes_include_configured_gateways(): void
    {
        $codes = app(GatewaySettingsService::class)->knownCodes();

        $this->assertContains('myfatoorah', $codes);
        $this->assertContains('stripe', $codes);
        $this->assertContains('paypal', $codes);
        $this->assertNotNull(app(GatewaySettingsService::class)->definition('myfatoorah'));
        $this->assertNull(app(GatewaySettingsService::class)->definition('no-such-gateway'));
    }

    /** @test */
    public function can_initiate_ok_when_enabled_configured_supported(): void
    {
        $result = app(PaymentGatewayRegistry::class)->canInitiate('myfatoorah', 'online', 'KWD');

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['reason']);
    }

    /** @test */
    public function disabled_myfatoorah_blocks_initiate_and_handler_without_transactions(): void
    {
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());
        $customer = $this->createCustomer();
        $order = $this->makeOrderForCustomer($customer);
        $this->assertSame('KWD', $order->fresh()->currency_code);

        $this->disableMyfatoorahViaSettings();

        $this->assertFalse(app(GatewaySettingsService::class)->isEnabled('myfatoorah'));

        $gate = app(PaymentGatewayRegistry::class)->canInitiate('myfatoorah', 'online', 'KWD');
        $this->assertFalse($gate['ok']);

        $response = app(PaymentCheckoutHandler::class)->handleOnlinePayment(
            $this->checkoutRequest($customer),
            $order->fresh(),
            (float) $order->total_price,
            'myfatoorah',
            'https://example.test/cb',
            'https://example.test/er',
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString(
            __('message.ERROR.PAYMENT_GATEWAY_UNAVAILABLE'),
            (string) $response->getContent()
        );
        $this->assertDatabaseCount('transactions', 0);
    }

    /** @test */
    public function misconfigured_empty_api_key_blocks_initiate(): void
    {
        config(['payment.gateways.myfatoorah.api_key' => '']);

        $this->assertFalse(app(MyFatoorahGateway::class)->isConfigured());

        $gate = app(PaymentGatewayRegistry::class)->canInitiate('myfatoorah', 'online', 'KWD');

        $this->assertFalse($gate['ok']);
        $this->assertSame('misconfigured', $gate['reason']);
    }

    /** @test */
    public function unsupported_currency_blocks_initiate_with_currency_message(): void
    {
        $gate = app(PaymentGatewayRegistry::class)->canInitiate('myfatoorah', 'online', 'USD');

        $this->assertFalse($gate['ok']);
        $this->assertSame('currency_unsupported', $gate['reason']);

        // Default seed catalog is USD, which MyFatoorah does not support, so the
        // handler must reject with the currency translation before any HTTP.
        $customer = $this->createCustomer();
        $order = $this->makeOrderForCustomer($customer);
        $this->assertSame('USD', $order->fresh()->currency_code);

        $response = app(PaymentCheckoutHandler::class)->handleOnlinePayment(
            $this->checkoutRequest($customer),
            $order->fresh(),
            (float) $order->total_price,
            'myfatoorah',
            'https://example.test/cb',
            'https://example.test/er',
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString(
            __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => 'USD']),
            (string) $response->getContent()
        );
        $this->assertDatabaseCount('transactions', 0);
    }

    /** @test */
    public function can_verify_true_while_disabled(): void
    {
        $this->disableMyfatoorahViaSettings();

        $registry = app(PaymentGatewayRegistry::class);

        $this->assertTrue($registry->canVerify('myfatoorah'));
        $this->assertFalse($registry->canVerify('no-such-gateway'));

        // Verify path must still resolve a disabled gateway.
        $this->assertInstanceOf(MyFatoorahGateway::class, $registry->resolve('myfatoorah'));
    }

    /** @test */
    public function stripe_paypal_known_but_not_initiable_by_default(): void
    {
        $registry = app(PaymentGatewayRegistry::class);

        $this->assertNotNull(app(GatewaySettingsService::class)->definition('stripe'));
        $this->assertNotNull(app(GatewaySettingsService::class)->definition('paypal'));

        $stripe = $registry->canInitiate('stripe', 'online', 'USD');
        $paypal = $registry->canInitiate('paypal', 'online', 'USD');

        $this->assertFalse($stripe['ok']);
        $this->assertFalse($paypal['ok']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\CheckoutTotals;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Gateway\StripeGateway;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentCurrencyResolver;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Tests\Feature\Currency\CurrencyTestCase;

class StripeGatewayTest extends CurrencyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCurrencyData();

        // Hermetic baseline regardless of the developer .env.
        config(['payment.gateways.stripe.secret_key' => 'sk_test_fake']);
        config(['payment.gateways.stripe.supported_currencies' => ['USD', 'EUR', 'KWD', 'SAR', 'AED']]);
    }

    private function makeOrderForCustomer(object $customer, float $subtotal = 100.0): Order
    {
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Stripe Customer',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 Stripe Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);

        return $order;
    }

    private function makeOrderInCurrency(string $code, float $subtotal = 100.0): Order
    {
        app(CurrencyService::class)->setCatalogCurrency(
            \App\Models\Currency::query()->where('code', $code)->firstOrFail()
        );

        return $this->makeOrderForCustomer($this->createCustomer(), $subtotal);
    }

    private function gateway(FakeStripeClient $client): StripeGateway
    {
        return new StripeGateway(app(PaymentCurrencyResolver::class), $client);
    }

    private function fakeClient(): FakeStripeClient
    {
        return new FakeStripeClient(
            new FakeCheckout(new FakeCheckoutSessions()),
            new FakePaymentIntents(),
            new FakeRefunds(),
        );
    }

    private function stripeObject(array $data): StripeObject
    {
        return StripeObject::constructFrom($data);
    }

    /** @test */
    public function implements_contract_with_all_seven_methods(): void
    {
        $gateway = $this->gateway($this->fakeClient());

        $this->assertInstanceOf(PaymentGatewayContract::class, $gateway);

        foreach (['createInvoice', 'verifyPayment', 'refund', 'name', 'supportsCurrency', 'code', 'isConfigured'] as $method) {
            $this->assertTrue(method_exists($gateway, $method), "Missing contract method: {$method}");
        }

        $this->assertSame('stripe', $gateway->code());
        $this->assertSame('stripe', $gateway->name());
    }

    /** @test */
    public function create_invoice_sends_minor_units_lowercase_currency_and_templated_urls(): void
    {
        $order = $this->makeOrderInCurrency('KWD', 13.25);
        $this->assertSame('KWD', $order->fresh()->currency_code);

        $client = $this->fakeClient();
        $client->checkoutFake->sessions->createResult = $this->stripeObject([
            'id' => 'cs_test_123',
            'url' => 'https://checkout.stripe.test/pay/cs_test_123',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'amount_total' => 13250,
            'currency' => 'kwd',
        ]);

        $callback = 'https://shop.test/checkout/callback';
        $error = 'https://shop.test/checkout/error?step=pay';

        $result = $this->gateway($client)->createInvoice($order, 13.25, $callback, $error);

        $this->assertTrue($result->success);
        $this->assertSame('https://checkout.stripe.test/pay/cs_test_123', $result->redirectUrl);
        $this->assertSame('cs_test_123', $result->gatewayTransactionId);
        $this->assertSame('pending', $result->status);
        $this->assertSame('KWD', $result->currency);

        $params = $client->checkoutFake->sessions->lastCreateParams;
        $this->assertSame('payment', $params['mode']);
        $this->assertSame('kwd', $params['line_items'][0]['price_data']['currency']);
        // 13.25 KWD x 1000 (three-decimal currency) = 13250 minor units.
        $this->assertSame(13250, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame(1, $params['line_items'][0]['quantity']);
        $this->assertStringStartsWith($callback, $params['success_url']);
        $this->assertStringContainsString('{CHECKOUT_SESSION_ID}', $params['success_url']);
        $this->assertStringContainsString('paymentId={CHECKOUT_SESSION_ID}', $params['success_url']);
        // errorUrl already carries a query string: the placeholder must join with &.
        $this->assertStringStartsWith($error, $params['cancel_url']);
        $this->assertStringContainsString('&paymentId={CHECKOUT_SESSION_ID}', $params['cancel_url']);
        $this->assertSame((string) $order->id, $params['metadata']['order_id']);

        // rawResponse is allowlisted to technical fields only.
        $this->assertSame(
            ['id', 'url', 'status', 'payment_status', 'amount_total', 'currency'],
            array_keys($result->rawResponse ?? [])
        );
    }

    /** @test */
    public function create_invoice_zero_decimal_currency_sends_amount_as_is(): void
    {
        $this->createCurrency('JPY', ['numeric_code' => '392', 'decimal_places' => 0]);
        $this->createRate(\App\Models\Currency::query()->where('code', 'JPY')->firstOrFail(), '150.0000000000');
        config(['payment.gateways.stripe.supported_currencies' => ['JPY']]);

        $order = $this->makeOrderInCurrency('JPY', 500.0);

        $client = $this->fakeClient();
        $client->checkoutFake->sessions->createResult = $this->stripeObject([
            'id' => 'cs_test_jpy',
            'url' => 'https://checkout.stripe.test/pay/cs_test_jpy',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'amount_total' => 500,
            'currency' => 'jpy',
        ]);

        $result = $this->gateway($client)->createInvoice($order, 500.0, 'https://shop.test/cb', 'https://shop.test/er');

        $this->assertTrue($result->success);

        $params = $client->checkoutFake->sessions->lastCreateParams;
        $this->assertSame('jpy', $params['line_items'][0]['price_data']['currency']);
        $this->assertSame(500, $params['line_items'][0]['price_data']['unit_amount']);
    }

    /** @test */
    public function create_invoice_unsupported_currency_fails_without_sdk_call(): void
    {
        config(['payment.gateways.stripe.supported_currencies' => ['USD']]);

        $order = $this->makeOrderInCurrency('KWD', 50.0);

        $client = $this->fakeClient();

        $result = $this->gateway($client)->createInvoice($order, 50.0, 'https://shop.test/cb', 'https://shop.test/er');

        $this->assertFalse($result->success);
        $this->assertSame(
            __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => 'KWD']),
            $result->errorMessage
        );
        $this->assertSame(0, $client->checkoutFake->sessions->createCalls);
    }

    /** @test */
    public function create_invoice_sdk_exception_fails_closed_without_secret_leakage(): void
    {
        $order = $this->makeOrderInCurrency('KWD', 50.0);

        $client = $this->fakeClient();
        $client->checkoutFake->sessions->createException =
            \Stripe\Exception\InvalidRequestException::factory('Invalid request: no such coupon foo (sk_test_fake_secret)');

        $result = $this->gateway($client)->createInvoice($order, 50.0, 'https://shop.test/cb', 'https://shop.test/er');

        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);
        $this->assertStringNotContainsString('sk_test_fake_secret', (string) $result->errorMessage);
        $this->assertNull($result->redirectUrl);
    }

    /** @test */
    public function verify_paid_session_returns_major_units_uppercase_currency(): void
    {
        $client = $this->fakeClient();
        $client->checkoutFake->sessions->retrieveResults['cs_test_123'] = $this->stripeObject([
            'id' => 'cs_test_123',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 13250,
            'currency' => 'kwd',
            'payment_intent' => 'pi_123',
        ]);
        $client->paymentIntentsFake->retrieveResult = $this->stripeObject([
            'id' => 'pi_123',
            'amount' => 13250,
            'currency' => 'kwd',
            'status' => 'succeeded',
        ]);

        $result = $this->gateway($client)->verifyPayment('cs_test_123');

        $this->assertTrue($result->success);
        $this->assertSame('cs_test_123', $result->gatewayTransactionId);
        $this->assertSame('paid', $result->status);
        $this->assertSame('KWD', $result->currency);
        $this->assertEqualsWithDelta(13.25, (float) $result->amount, 0.000001);
        $this->assertSame('pi_123', $client->paymentIntentsFake->lastRetrieveId);
    }

    /** @test */
    public function verify_unpaid_session_returns_failure(): void
    {
        $client = $this->fakeClient();
        $client->checkoutFake->sessions->retrieveResults['cs_test_open'] = $this->stripeObject([
            'id' => 'cs_test_open',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'amount_total' => 10000,
            'currency' => 'usd',
            'payment_intent' => null,
        ]);

        $result = $this->gateway($client)->verifyPayment('cs_test_open');

        $this->assertFalse($result->success);
        $this->assertSame('failed', $result->status);
        $this->assertSame('USD', $result->currency);
        $this->assertEqualsWithDelta(100.0, (float) $result->amount, 0.000001);
    }

    /** @test */
    public function verify_amount_cross_check_mismatch_fails_closed(): void
    {
        $client = $this->fakeClient();
        $client->checkoutFake->sessions->retrieveResults['cs_test_123'] = $this->stripeObject([
            'id' => 'cs_test_123',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 13250,
            'currency' => 'kwd',
            'payment_intent' => 'pi_123',
        ]);
        // PaymentIntent disagrees with the session total.
        $client->paymentIntentsFake->retrieveResult = $this->stripeObject([
            'id' => 'pi_123',
            'amount' => 13000,
            'currency' => 'kwd',
            'status' => 'succeeded',
        ]);

        $result = $this->gateway($client)->verifyPayment('cs_test_123');

        $this->assertFalse($result->success);
        $this->assertSame('Payment amount or currency mismatch', $result->errorMessage);
    }

    /** @test */
    public function verify_currency_cross_check_mismatch_fails_closed(): void
    {
        $client = $this->fakeClient();
        $client->checkoutFake->sessions->retrieveResults['cs_test_123'] = $this->stripeObject([
            'id' => 'cs_test_123',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 13250,
            'currency' => 'kwd',
            'payment_intent' => 'pi_123',
        ]);
        $client->paymentIntentsFake->retrieveResult = $this->stripeObject([
            'id' => 'pi_123',
            'amount' => 13250,
            'currency' => 'usd',
            'status' => 'succeeded',
        ]);

        $result = $this->gateway($client)->verifyPayment('cs_test_123');

        $this->assertFalse($result->success);
    }

    /** @test */
    public function refund_succeeds_only_on_succeeded_status(): void
    {
        $order = $this->makeOrderInCurrency('KWD', 13.25);
        $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'stripe',
            'status' => 'paid',
            'amount' => 13.25,
            'currency' => 'KWD',
            'gateway_transaction_id' => 'cs_test_123',
            'invoice_id' => 42,
        ]);

        $client = $this->fakeClient();
        $client->checkoutFake->sessions->retrieveResults['cs_test_123'] = $this->stripeObject([
            'id' => 'cs_test_123',
            'payment_status' => 'paid',
            'amount_total' => 13250,
            'currency' => 'kwd',
            'payment_intent' => 'pi_123',
        ]);
        $client->refundsFake->createResult = $this->stripeObject([
            'id' => 're_123',
            'status' => 'succeeded',
            'amount' => 13250,
            'currency' => 'kwd',
            'payment_intent' => 'pi_123',
        ]);

        $result = $this->gateway($client)->refund($order->fresh(), 13.25, 'customer request');

        $this->assertTrue($result->success);
        $this->assertSame('re_123', $result->gatewayTransactionId);
        $this->assertSame('succeeded', $result->status);
        $this->assertSame('KWD', $result->currency);
        $this->assertSame('pi_123', $client->refundsFake->lastCreateParams['payment_intent']);
        $this->assertSame(13250, $client->refundsFake->lastCreateParams['amount']);
    }

    /** @test */
    public function refund_pending_status_fails_closed(): void
    {
        $order = $this->makeOrderInCurrency('KWD', 13.25);
        $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'stripe',
            'status' => 'paid',
            'amount' => 13.25,
            'currency' => 'KWD',
            'gateway_transaction_id' => 'cs_test_123',
            'invoice_id' => 42,
        ]);

        $client = $this->fakeClient();
        $client->checkoutFake->sessions->retrieveResults['cs_test_123'] = $this->stripeObject([
            'id' => 'cs_test_123',
            'payment_status' => 'paid',
            'amount_total' => 13250,
            'currency' => 'kwd',
            'payment_intent' => 'pi_123',
        ]);
        $client->refundsFake->createResult = $this->stripeObject([
            'id' => 're_123',
            'status' => 'pending',
            'amount' => 13250,
            'currency' => 'kwd',
            'payment_intent' => 'pi_123',
        ]);

        $result = $this->gateway($client)->refund($order->fresh(), 13.25);

        $this->assertFalse($result->success);
        $this->assertSame('Refund not confirmed by gateway', $result->errorMessage);
    }

    /** @test */
    public function refund_failed_status_fails_closed(): void
    {
        $order = $this->makeOrderInCurrency('KWD', 13.25);
        $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'stripe',
            'status' => 'paid',
            'amount' => 13.25,
            'currency' => 'KWD',
            'gateway_transaction_id' => 'cs_test_123',
            'invoice_id' => 42,
        ]);

        $client = $this->fakeClient();
        $client->checkoutFake->sessions->retrieveResults['cs_test_123'] = $this->stripeObject([
            'id' => 'cs_test_123',
            'payment_status' => 'paid',
            'amount_total' => 13250,
            'currency' => 'kwd',
            'payment_intent' => 'pi_123',
        ]);
        $client->refundsFake->createResult = $this->stripeObject([
            'id' => 're_123',
            'status' => 'failed',
            'amount' => 13250,
            'currency' => 'kwd',
            'payment_intent' => 'pi_123',
        ]);

        $result = $this->gateway($client)->refund($order->fresh(), 13.25);

        $this->assertFalse($result->success);
    }

    /** @test */
    public function refund_without_payment_intent_fails_closed_without_refund_call(): void
    {
        $order = $this->makeOrderInCurrency('KWD', 13.25);
        $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'stripe',
            'status' => 'paid',
            'amount' => 13.25,
            'currency' => 'KWD',
            'gateway_transaction_id' => 'cs_test_nopi',
            'invoice_id' => 42,
        ]);

        $client = $this->fakeClient();
        $client->checkoutFake->sessions->retrieveResults['cs_test_nopi'] = $this->stripeObject([
            'id' => 'cs_test_nopi',
            'payment_status' => 'paid',
            'amount_total' => 13250,
            'currency' => 'kwd',
            'payment_intent' => null,
        ]);

        $result = $this->gateway($client)->refund($order->fresh(), 13.25);

        $this->assertFalse($result->success);
        $this->assertSame(0, $client->refundsFake->createCalls);
    }

    /** @test */
    public function is_configured_reads_secret_key(): void
    {
        config(['payment.gateways.stripe.secret_key' => 'sk_test_abc']);
        $this->assertTrue($this->gateway($this->fakeClient())->isConfigured());

        config(['payment.gateways.stripe.secret_key' => '']);
        $this->assertFalse($this->gateway($this->fakeClient())->isConfigured());

        config(['payment.gateways.stripe.secret_key' => null]);
        $this->assertFalse($this->gateway($this->fakeClient())->isConfigured());
    }

    /** @test */
    public function supports_currency_is_case_insensitive(): void
    {
        $gateway = $this->gateway($this->fakeClient());

        $this->assertTrue($gateway->supportsCurrency('kwd'));
        $this->assertTrue($gateway->supportsCurrency('KWD'));
        $this->assertTrue($gateway->supportsCurrency('Usd'));
        $this->assertFalse($gateway->supportsCurrency('JPY'));
    }

    /** @test */
    public function minor_unit_converter_handles_decimal_exponents(): void
    {
        // Three-decimal: 13.25 KWD -> 13250.
        $this->assertSame(13250, StripeGateway::toMinorUnits(13.25, 'KWD'));
        $this->assertSame(13250, StripeGateway::toMinorUnits(13.25, 'kwd'));
        $this->assertEqualsWithDelta(13.25, StripeGateway::fromMinorUnits(13250, 'KWD'), 0.000001);

        // Two-decimal: 10.99 USD -> 1099.
        $this->assertSame(1099, StripeGateway::toMinorUnits(10.99, 'USD'));
        $this->assertEqualsWithDelta(10.99, StripeGateway::fromMinorUnits(1099, 'USD'), 0.000001);

        // Zero-decimal: 500 JPY -> 500 (amount sent as-is).
        $this->assertSame(500, StripeGateway::toMinorUnits(500, 'JPY'));
        $this->assertSame(500.0, StripeGateway::fromMinorUnits(500, 'JPY'));
    }
}

class FakeCheckoutSessions
{
    public ?array $lastCreateParams = null;

    public int $createCalls = 0;

    public mixed $createResult = null;

    public ?\Throwable $createException = null;

    /** @var array<string, mixed> */
    public array $retrieveResults = [];

    public ?\Throwable $retrieveException = null;

    public ?string $lastRetrieveId = null;

    public function create($params = null, $opts = null)
    {
        $this->createCalls++;
        $this->lastCreateParams = is_array($params) ? $params : [];

        if ($this->createException !== null) {
            throw $this->createException;
        }

        return $this->createResult;
    }

    public function retrieve($id, $params = null, $opts = null)
    {
        $this->lastRetrieveId = $id;

        if ($this->retrieveException !== null) {
            throw $this->retrieveException;
        }

        return $this->retrieveResults[$id] ?? $this->retrieveResults['default'] ?? null;
    }
}

class FakeCheckout
{
    public function __construct(public FakeCheckoutSessions $sessions) {}
}

class FakePaymentIntents
{
    public mixed $retrieveResult = null;

    public ?\Throwable $retrieveException = null;

    public ?string $lastRetrieveId = null;

    public function retrieve($id, $params = null, $opts = null)
    {
        $this->lastRetrieveId = $id;

        if ($this->retrieveException !== null) {
            throw $this->retrieveException;
        }

        return $this->retrieveResult;
    }
}

class FakeRefunds
{
    public ?array $lastCreateParams = null;

    public int $createCalls = 0;

    public mixed $createResult = null;

    public ?\Throwable $createException = null;

    public function create($params = null, $opts = null)
    {
        $this->createCalls++;
        $this->lastCreateParams = is_array($params) ? $params : [];

        if ($this->createException !== null) {
            throw $this->createException;
        }

        return $this->createResult;
    }
}

class FakeStripeClient extends StripeClient
{
    public FakeCheckout $checkoutFake;

    public FakePaymentIntents $paymentIntentsFake;

    public FakeRefunds $refundsFake;

    public function __construct(FakeCheckout $checkout, FakePaymentIntents $paymentIntents, FakeRefunds $refunds)
    {
        $this->checkoutFake = $checkout;
        $this->paymentIntentsFake = $paymentIntents;
        $this->refundsFake = $refunds;
    }

    public function __get($name)
    {
        return match ($name) {
            'checkout' => $this->checkoutFake,
            'paymentIntents' => $this->paymentIntentsFake,
            'refunds' => $this->refundsFake,
            default => throw new \BadMethodCallException('Unknown service ' . $name),
        };
    }

    public function getService($name)
    {
        return $this->__get($name);
    }
}

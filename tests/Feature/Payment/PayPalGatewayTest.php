<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\CheckoutTotals;
use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Gateway\PayPalGateway;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentCurrencyResolver;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * PayPalGateway adapter tests with a fake SDK client.
 *
 * Sandbox is BLOCKED (no credentials): the fake is injected via the gateway's
 * client-factory seam, so no test below ever touches the network.
 */
class PayPalGatewayTest extends CurrencyTestCase
{
    private FakePayPalClient $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCurrencyData();
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());

        // Hermetic baseline regardless of the developer .env.
        config(['payment.gateways.paypal.mode' => 'sandbox']);
        config(['payment.gateways.paypal.client_id' => 'test-client-id']);
        config(['payment.gateways.paypal.client_secret' => 'test-client-secret']);
        config(['payment.gateways.paypal.supported_currencies' => ['KWD', 'USD', 'EUR']]);

        $this->fake = new FakePayPalClient();
    }

    private function gateway(): PayPalGateway
    {
        return new PayPalGateway(
            app(PaymentCurrencyResolver::class),
            fn (string $currency = 'USD') => $this->fake,
        );
    }

    private function makeOrder(float $subtotal = 13.25): Order
    {
        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'PayPal Customer',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 PayPal Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);
        $this->assertSame('KWD', $order->fresh()->currency_code);

        return $order->fresh();
    }

    private function orderPayload(string $orderId, string $status, string $currency = 'KWD', string $value = '13.25', bool $withCaptures = false): array
    {
        $unit = [
            'reference_id' => 'order-'.$orderId,
            'amount' => ['currency_code' => $currency, 'value' => $value],
        ];

        if ($withCaptures) {
            $unit['payments'] = [
                'captures' => [
                    [
                        'id' => 'CAP-'.$orderId,
                        'status' => 'COMPLETED',
                        'amount' => ['currency_code' => $currency, 'value' => $value],
                    ],
                ],
            ];
        }

        return [
            'id' => $orderId,
            'status' => $status,
            'intent' => 'CAPTURE',
            'purchase_units' => [$unit],
        ];
    }

    private function createResponse(string $paypalOrderId = 'PAYPAL-ORDER-1'): array
    {
        return [
            'id' => $paypalOrderId,
            'status' => 'CREATED',
            'intent' => 'CAPTURE',
            'links' => [
                ['href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/'.$paypalOrderId, 'rel' => 'self', 'method' => 'GET'],
                ['href' => 'https://www.sandbox.paypal.com/checkoutnow?token='.$paypalOrderId, 'rel' => 'approve', 'method' => 'GET'],
                ['href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/'.$paypalOrderId.'/capture', 'rel' => 'capture', 'method' => 'POST'],
            ],
        ];
    }

    /** @test */
    public function conforms_to_the_gateway_contract(): void
    {
        $gateway = $this->gateway();

        $this->assertInstanceOf(PaymentGatewayContract::class, $gateway);
        $this->assertSame('paypal', $gateway->code());
        $this->assertSame('paypal', $gateway->name());
    }

    /** @test */
    public function create_invoice_sends_capture_intent_with_major_unit_amount_and_urls(): void
    {
        $order = $this->makeOrder(13.25);
        $this->fake->stubs['createOrder'] = $this->createResponse('PAYPAL-ORDER-1');

        $result = $this->gateway()->createInvoice(
            $order,
            13.25,
            'https://example.test/cb',
            'https://example.test/er',
        );

        $this->assertTrue($result->success);
        $this->assertSame('https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-ORDER-1', $result->redirectUrl);
        $this->assertSame('PAYPAL-ORDER-1', $result->gatewayTransactionId);
        $this->assertSame(13.25, $result->amount);
        $this->assertSame('KWD', $result->currency);
        $this->assertSame('pending', $result->status);

        $payload = $this->fake->calls['createOrder'][0];
        $this->assertSame('CAPTURE', $payload['intent']);
        // KWD carries 3dp: the provider value keeps all three decimals.
        $this->assertSame('13.250', $payload['purchase_units'][0]['amount']['value']);
        $this->assertSame('KWD', $payload['purchase_units'][0]['amount']['currency_code']);
        $this->assertSame((string) $order->id, $payload['purchase_units'][0]['invoice_id']);
        $this->assertSame('https://example.test/cb', $payload['application_context']['return_url']);
        $this->assertSame('https://example.test/er', $payload['application_context']['cancel_url']);

        // Deterministic idempotency key per order+attempt.
        $this->assertSame('order-'.$order->id.'-1', $this->fake->headers['PayPal-Request-Id']);

        // Raw response is allowlisted: no PII/secrets, only technical fields.
        $this->assertSame(
            ['id', 'status', 'intent'],
            array_keys($result->rawResponse),
        );
    }

    /** @test */
    public function create_invoice_uses_payment_attempt_metadata_in_idempotency_key(): void
    {
        $order = $this->makeOrder(13.25);
        $this->fake->stubs['createOrder'] = $this->createResponse('PAYPAL-ORDER-2');

        $result = $this->gateway()->createInvoice(
            $order,
            13.25,
            'https://example.test/cb',
            'https://example.test/er',
            ['payment_attempt' => 3],
        );

        $this->assertTrue($result->success);
        $this->assertSame('order-'.$order->id.'-3', $this->fake->headers['PayPal-Request-Id']);
    }

    /** @test */
    public function create_invoice_is_blocked_for_an_unsupported_currency_without_client_call(): void
    {
        $order = $this->makeOrder(13.25);
        config(['payment.gateways.paypal.supported_currencies' => ['USD']]);

        $result = $this->gateway()->createInvoice(
            $order,
            13.25,
            'https://example.test/cb',
            'https://example.test/er',
        );

        $this->assertFalse($result->success);
        $this->assertSame(
            __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => 'KWD']),
            $result->errorMessage
        );
        $this->assertSame([], $this->fake->calls);
    }

    /** @test */
    public function create_invoice_fails_closed_on_client_exception(): void
    {
        $order = $this->makeOrder(13.25);
        $this->fake->stubs['createOrder'] = new \RuntimeException('connection refused');

        $result = $this->gateway()->createInvoice(
            $order,
            13.25,
            'https://example.test/cb',
            'https://example.test/er',
        );

        $this->assertFalse($result->success);
        $this->assertSame('Payment gateway request failed', $result->errorMessage);
    }

    /** @test */
    public function create_invoice_fails_closed_without_approve_link(): void
    {
        $order = $this->makeOrder(13.25);
        $this->fake->stubs['createOrder'] = ['id' => 'PAYPAL-ORDER-1', 'status' => 'CREATED', 'links' => []];

        $result = $this->gateway()->createInvoice(
            $order,
            13.25,
            'https://example.test/cb',
            'https://example.test/er',
        );

        $this->assertFalse($result->success);
    }

    /** @test */
    public function verify_approved_order_captures_and_returns_paid(): void
    {
        $this->fake->stubs['showOrderDetails'] = $this->orderPayload('PAYPAL-ORDER-1', 'APPROVED');
        $this->fake->stubs['capturePaymentOrder'] = $this->orderPayload('PAYPAL-ORDER-1', 'COMPLETED', withCaptures: true);

        $result = $this->gateway()->verifyPayment('PAYPAL-ORDER-1');

        $this->assertTrue($result->success);
        $this->assertSame('PAYPAL-ORDER-1', $result->gatewayTransactionId);
        $this->assertSame(13.25, $result->amount);
        $this->assertSame('KWD', $result->currency);
        $this->assertSame('paid', $result->status);
        $this->assertSame(['PAYPAL-ORDER-1'], $this->fake->calls['capturePaymentOrder']);
    }

    /** @test */
    public function verify_already_captured_order_succeeds_without_re_capture(): void
    {
        $this->fake->stubs['showOrderDetails'] = $this->orderPayload('PAYPAL-ORDER-1', 'COMPLETED', withCaptures: true);

        $result = $this->gateway()->verifyPayment('PAYPAL-ORDER-1');

        $this->assertTrue($result->success);
        $this->assertSame(13.25, $result->amount);
        $this->assertSame('KWD', $result->currency);
        $this->assertArrayNotHasKey('capturePaymentOrder', $this->fake->calls);
    }

    /** @test */
    public function verify_fails_closed_on_amount_mismatch(): void
    {
        $payload = $this->orderPayload('PAYPAL-ORDER-1', 'COMPLETED', withCaptures: true);
        $payload['purchase_units'][0]['payments']['captures'][0]['amount']['value'] = '10.00';
        $this->fake->stubs['showOrderDetails'] = $payload;

        $result = $this->gateway()->verifyPayment('PAYPAL-ORDER-1');

        $this->assertFalse($result->success);
    }

    /** @test */
    public function verify_fails_for_non_completed_status_without_capture(): void
    {
        $this->fake->stubs['showOrderDetails'] = $this->orderPayload('PAYPAL-ORDER-1', 'CREATED');

        $result = $this->gateway()->verifyPayment('PAYPAL-ORDER-1');

        $this->assertFalse($result->success);
        $this->assertArrayNotHasKey('capturePaymentOrder', $this->fake->calls);
    }

    /** @test */
    public function verify_fails_closed_on_client_exception(): void
    {
        $this->fake->stubs['showOrderDetails'] = new \RuntimeException('connection refused');

        $result = $this->gateway()->verifyPayment('PAYPAL-ORDER-1');

        $this->assertFalse($result->success);
        $this->assertSame('Payment gateway request failed', $result->errorMessage);
    }

    /** @test */
    public function refund_succeeds_only_on_completed_status(): void
    {
        $order = $this->makeOrder(13.25);
        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'invoice_id' => 'PAYPAL-ORDER-9',
            'payment_method' => 'paypal',
            'status' => 'paid',
            'amount' => 13.25,
            'currency' => 'KWD',
            'gateway_transaction_id' => 'PAYPAL-ORDER-9',
            'gateway_response' => ['id' => 'PAYPAL-ORDER-9'],
        ]);
        $this->fake->stubs['showOrderDetails'] = $this->orderPayload('PAYPAL-ORDER-9', 'COMPLETED', withCaptures: true);
        $this->fake->stubs['refundCapturedPayment'] = ['id' => 'REF-1', 'status' => 'COMPLETED'];

        $result = $this->gateway()->refund($order->fresh(), 13.25, 'customer request');

        $this->assertTrue($result->success);
        $this->assertSame('REF-1', $result->gatewayTransactionId);
        $this->assertSame(13.25, $result->amount);
        $this->assertSame('KWD', $result->currency);

        $call = $this->fake->calls['refundCapturedPayment'][0];
        $this->assertSame('CAP-PAYPAL-ORDER-9', $call['captureId']);
        $this->assertSame('order-'.$order->id, $call['invoiceId']);
        $this->assertSame(13.25, $call['amount']);
    }

    /** @test */
    public function refund_fails_closed_on_failed_or_pending_status(): void
    {
        foreach (['FAILED', 'PENDING'] as $status) {
            $order = $this->makeOrder(13.25);
            Transaction::create([
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'invoice_id' => 'PAYPAL-ORDER-9',
                'payment_method' => 'paypal',
                'status' => 'paid',
                'amount' => 13.25,
                'currency' => 'KWD',
                'gateway_transaction_id' => 'PAYPAL-ORDER-9',
                'gateway_response' => ['id' => 'PAYPAL-ORDER-9'],
            ]);
            $this->fake->stubs['showOrderDetails'] = $this->orderPayload('PAYPAL-ORDER-9', 'COMPLETED', withCaptures: true);
            $this->fake->stubs['refundCapturedPayment'] = ['id' => 'REF-1', 'status' => $status];

            $result = $this->gateway()->refund($order->fresh(), 13.25);

            $this->assertFalse($result->success, "Refund status $status must fail closed");
        }
    }

    /** @test */
    public function refund_fails_closed_without_captured_payment(): void
    {
        $order = $this->makeOrder(13.25);
        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'invoice_id' => 'PAYPAL-ORDER-9',
            'payment_method' => 'paypal',
            'status' => 'paid',
            'amount' => 13.25,
            'currency' => 'KWD',
            'gateway_transaction_id' => 'PAYPAL-ORDER-9',
            'gateway_response' => ['id' => 'PAYPAL-ORDER-9'],
        ]);
        $this->fake->stubs['showOrderDetails'] = $this->orderPayload('PAYPAL-ORDER-9', 'COMPLETED', withCaptures: false);

        $result = $this->gateway()->refund($order->fresh(), 13.25);

        $this->assertFalse($result->success);
        $this->assertArrayNotHasKey('refundCapturedPayment', $this->fake->calls);
    }

    /** @test */
    public function refund_fails_closed_on_ambiguous_captures(): void
    {
        $order = $this->makeOrder(13.25);
        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'invoice_id' => 'PAYPAL-ORDER-9',
            'payment_method' => 'paypal',
            'status' => 'paid',
            'amount' => 13.25,
            'currency' => 'KWD',
            'gateway_transaction_id' => 'PAYPAL-ORDER-9',
            'gateway_response' => ['id' => 'PAYPAL-ORDER-9'],
        ]);
        $payload = $this->orderPayload('PAYPAL-ORDER-9', 'COMPLETED', withCaptures: true);
        $payload['purchase_units'][] = $payload['purchase_units'][0];
        $this->fake->stubs['showOrderDetails'] = $payload;

        $result = $this->gateway()->refund($order->fresh(), 13.25);

        $this->assertFalse($result->success);
        $this->assertArrayNotHasKey('refundCapturedPayment', $this->fake->calls);
    }

    /** @test */
    public function is_configured_reflects_credentials_per_mode(): void
    {
        config(['payment.gateways.paypal.mode' => 'sandbox']);
        $this->assertTrue($this->gateway()->isConfigured());

        config(['payment.gateways.paypal.mode' => 'live']);
        $this->assertTrue($this->gateway()->isConfigured());

        config(['payment.gateways.paypal.client_secret' => '']);
        $this->assertFalse($this->gateway()->isConfigured());

        config(['payment.gateways.paypal.client_secret' => 'test-client-secret']);
        config(['payment.gateways.paypal.client_id' => '  ']);
        $this->assertFalse($this->gateway()->isConfigured());
    }

    /** @test */
    public function supports_currency_is_case_insensitive(): void
    {
        $gateway = $this->gateway();

        $this->assertTrue($gateway->supportsCurrency('kwd'));
        $this->assertTrue($gateway->supportsCurrency('KWD'));
        $this->assertFalse($gateway->supportsCurrency('XXX'));
    }
}

/**
 * In-memory stand-in for Srmklive\PayPal\Services\PayPal. Records calls and
 * request headers; stubbed responses (arrays, closures, or Throwables) drive
 * each test. Never touches the network.
 */
final class FakePayPalClient
{
    /** @var array<string, list<mixed>> */
    public array $calls = [];

    /** @var array<string, string> */
    public array $headers = [];

    /** @var array<string, mixed> */
    public array $stubs = [];

    public function setRequestHeader(string $key, string $value): self
    {
        $this->headers[$key] = $value;

        return $this;
    }

    public function createOrder(array $data): mixed
    {
        return $this->handle('createOrder', $data);
    }

    public function showOrderDetails(string $orderId): mixed
    {
        return $this->handle('showOrderDetails', $orderId);
    }

    public function capturePaymentOrder(string $orderId, array $data = []): mixed
    {
        $this->calls['capturePaymentOrder'][] = $orderId;

        return $this->respond('capturePaymentOrder');
    }

    public function refundCapturedPayment(string $captureId, string $invoiceId, float $amount, string $note): mixed
    {
        $this->calls['refundCapturedPayment'][] = compact('captureId', 'invoiceId', 'amount', 'note');

        return $this->respond('refundCapturedPayment');
    }

    private function handle(string $method, mixed $payload): mixed
    {
        $this->calls[$method][] = $payload;

        return $this->respond($method);
    }

    private function respond(string $method): mixed
    {
        $stub = $this->stubs[$method] ?? null;

        if ($stub instanceof \Throwable) {
            throw $stub;
        }

        if ($stub instanceof \Closure) {
            return $stub();
        }

        return $stub;
    }
}

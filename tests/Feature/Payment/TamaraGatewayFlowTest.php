<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\CheckoutTotals;
use App\DTOs\GatewayResult;
use App\Events\PaymentSucceeded;
use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Gateway\TamaraGateway;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentCheckoutHandler;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentRefundService;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * Tamara gateway flow: initiation through the canonical
 * PaymentCheckoutHandler, the OrderController order_id/orderId callback
 * fallbacks (Tamara merchant_urls are called back with Tamara's own echo
 * params — UNVERIFIED exact param names until a live sandbox redirect), the
 * capture-unresolved recovery invariant, and DB-backed adapter refunds via
 * the PaymentRefundService authority (both the captured-money simplified
 * refund AND the authorised-money cancel-void path).
 *
 * No live credentials: every Tamara interaction runs through Http::fake(),
 * gateway re-verification runs through a mocked PaymentGatewayFactory, and
 * Tamara's network is never touched.
 *
 * STATUS: DB-backed (RefreshDatabase) — NOT RUNNABLE in the environment
 * where MySQL is unreachable (see
 * docs/audits/tamara-payment-integration-audit.md §Tests). Runnable where a
 * MySQL test database exists:
 *
 *   php vendor\phpunit\phpunit\phpunit tests\Feature\Payment\TamaraGatewayFlowTest.php
 */
class TamaraGatewayFlowTest extends CurrencyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Any provider URL not covered by a test's Http::fake() must fail
        // loudly instead of attempting a real network call (e.g. a
        // precedence regression falling back to myfatoorah verification).
        Http::preventStrayRequests();

        config(['payment.gateways.tamara.class' => TamaraGateway::class]);
        config(['payment.gateways.tamara.enabled' => true]);
        config(['payment.gateways.tamara.environment' => 'sandbox']);
        config(['payment.gateways.tamara.api_url' => null]);
        config(['payment.gateways.tamara.api_token' => 'tamara_flow_api_token']);
        config(['payment.gateways.tamara.notification_token' => 'flow_notification_jwt_secret']);
        config(['payment.gateways.tamara.supported_currencies' => ['SAR', 'AED', 'BHD', 'KWD', 'OMR']]);
        config(['payment.gateways.tamara.eligibility_timeout_ms' => 200]);
        config(['payment.gateways.tamara.capture_attempts' => 2]);
        config(['payment.gateways.tamara.payment_type' => 'PAY_BY_INSTALMENTS']);
        config(['payment.gateways.tamara.instalments' => 3]);
    }

    protected function tearDown(): void
    {
        // Parent first: the app teardown runs the RefreshDatabase rollback
        // (beforeApplicationDestroyed callbacks) — it must never be skipped
        // when Mockery validation throws on an unfulfilled expectation.
        parent::tearDown();

        \Mockery::close();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function makeOrder(float $subtotal, string $currency): Order
    {
        $this->seedCurrencyData(); // USD + KWD + SAR (seeded set) — SAR is Tamara-supported

        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', $currency)->firstOrFail());
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', $currency)->firstOrFail());

        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Tamara Flow Customer',
                'user_phone' => '0555000001',
                'user_email' => $customer->email,
                'address' => '123 Tamara Flow Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);

        return $order->fresh();
    }

    private function initiationRequest(Order $order): \Illuminate\Http\Request
    {
        $request = \Illuminate\Http\Request::create('/dummy', 'POST', ['type' => 'web']);
        $request->setUserResolver(fn () => $order->user);

        return $request;
    }

    private function initiate(Order $order, float $amount): \Illuminate\Http\JsonResponse
    {
        return app(PaymentCheckoutHandler::class)
            ->handleOnlinePayment($this->initiationRequest($order), $order, $amount, 'tamara');
    }

    private function webToken(): string
    {
        return JWT::encode([
            'exp' => time() + 300,
            'iat' => time(),
            'iss' => 'Tamara',
        ], 'flow_notification_jwt_secret', 'HS256');
    }

    private function postTamaraWebhook(string $orderId, string $eventType = 'order_authorised', array $data = [])
    {
        return $this->call(
            'POST',
            '/api/v1/general/checkout/webhooks/tamara?tamaraToken=' . urlencode($this->webToken()),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(array_merge([
                'order_id' => $orderId,
                'order_reference_id' => 'ref-flow',
                'order_number' => 'MEEM-FLOW',
                'event_type' => $eventType,
                'data' => $data,
            ])),
        );
    }

    /**
     * Mock gateway re-verification behind the factory seam. $makeTimes is
     * explicit (capture-trail deliveries perform a SECOND make() from a
     * fresh read — see TamaraWebhookTest).
     */
    private function mockVerify(string $gateway, string $ref, int $makeTimes, GatewayResult ...$results): void
    {
        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->times(count($results))
            ->with($ref)
            ->andReturn(...$results);

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')
            ->times($makeTimes)
            ->with($gateway)
            ->andReturn($mockGateway);

        // Bind BEFORE the first request: the routing cache resolves the
        // controller per in-process call, and a factory instance bound
        // later never reaches it (see TapWebhookTest note).
        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);
    }

    private function paidResult(string $ref, float $amount, string $currency): GatewayResult
    {
        return new GatewayResult(
            success: true,
            gatewayTransactionId: $ref,
            amount: $amount,
            currency: $currency,
            status: 'paid',
            rawResponse: [
                'order_id' => $ref,
                'status' => 'authorised',
                'total_amount' => ['amount' => $amount, 'currency' => $currency],
                'amounts' => [
                    'captured_amount' => ['amount' => 0, 'currency' => $currency],
                    'refunded_amount' => ['amount' => 0, 'currency' => $currency],
                    'canceled_amount' => ['amount' => 0, 'currency' => $currency],
                ],
                'captures' => [],
                'refunds' => [],
                'cancels' => [],
            ],
        );
    }

    private function pendingResult(string $ref, float $amount, string $currency): GatewayResult
    {
        return new GatewayResult(
            success: false,
            gatewayTransactionId: $ref,
            amount: $amount,
            currency: $currency,
            status: 'pending',
            errorMessage: 'Payment gateway error',
        );
    }

    // -----------------------------------------------------------------
    // Initiation (canonical handler path)
    // -----------------------------------------------------------------

    /** @test */
    public function tamara_initiation_creates_the_pending_transaction_and_returns_the_redirect(): void
    {
        Http::fake([
            'api-sandbox.tamara.co/checkout' => Http::response([
                'order_id' => '870e6c88-flow-1-4a44-9ec0-7f7b8ed65e0c',
                'checkout_id' => 'checkout_flow_1',
                'status' => 'new',
                'checkout_url' => 'https://checkout.tamara.co/checkout?orderId=870e6c88-flow-1-4a44-9ec0-7f7b8ed65e0c',
            ], 201),
        ]);

        $order = $this->makeOrder(100.0, 'SAR');

        $response = $this->initiate($order, 100.0);

        $this->assertSame(200, $response->status());
        $this->assertTrue((bool) $response->getData()->success);
        $this->assertSame(
            'https://checkout.tamara.co/checkout?orderId=870e6c88-flow-1-4a44-9ec0-7f7b8ed65e0c',
            $response->getData()->data->url,
        );

        $txn = Transaction::query()->where('order_id', $order->id)->where('payment_method', 'tamara')->first();
        $this->assertNotNull($txn);
        $this->assertSame('pending', $txn->status);
        $this->assertSame(100.0, (float) $txn->amount);
        $this->assertSame('SAR', $txn->currency);
        $this->assertSame('870e6c88-flow-1-4a44-9ec0-7f7b8ed65e0c', $txn->gateway_transaction_id);

        // The persisted session allowlist mirrors TamaraGateway::sanitizeSession.
        $stored = (array) $txn->gateway_response;
        $this->assertSame('870e6c88-flow-1-4a44-9ec0-7f7b8ed65e0c', $stored['order_id'] ?? null);
        $this->assertSame('checkout_flow_1', $stored['checkout_id'] ?? null);
        $this->assertSame('new', $stored['status'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f\-]{36}$/i', (string) ($stored['order_reference_id'] ?? ''));

        // No checkout_url / provider PII echoes into persistence.
        $this->assertArrayNotHasKey('checkout_url', $stored);
        $this->assertStringNotContainsString('tamara.co/customer', json_encode($stored));

        Http::assertSent(function ($request) use ($order) {
            $body = $request->data();

            return $request->method() === 'POST'
                && $body['total_amount']['amount'] === 100.0
                && $body['total_amount']['currency'] === 'SAR'
                // Documented composition: Σ items + tax − discount == total.
                && $body['tax_amount']['amount'] === 0.0
                && $body['shipping_amount']['amount'] === 0.0
                && count($body['items']) === 1
                && $body['items'][0]['total_amount']['amount'] === 100.0
                && $body['items'][0]['reference_id'] === $order->order_number
                // Item schema'd fields: the no-double-count decomposition
                // (unit price == line total for qty 1; order-level tax only).
                && $body['items'][0]['unit_price']['amount'] === 100.0
                && $body['items'][0]['tax_amount']['amount'] === 0.0
                && $body['items'][0]['discount_amount']['amount'] === 0.0
                // shipping_address: buyer identity + snapshot keys; a string
                // snapshot contributes its text to line1 and leaves the city
                // EMPTY (nothing fabricated).
                && $body['shipping_address']['first_name'] === 'Tamara'
                && $body['shipping_address']['last_name'] === 'Flow Customer'
                && $body['shipping_address']['line1'] === '123 Tamara Flow Street'
                && $body['shipping_address']['city'] === ''
                && $body['shipping_address']['country_code'] === 'KW'
                && $body['consumer']['phone_number'] === '0555000001'
                // String-address orders resolve through the documented
                // Meem home-default (KW) — snapshot absent ⇒ default.
                && $body['country_code'] === 'KW'
                && $body['payment_type'] === 'PAY_BY_INSTALMENTS'
                && $body['instalments'] === 3
                // locale contract: ar|en app locale → ar_SA|en_US.
                && in_array($body['locale'], ['ar_SA', 'en_US'], true)
                && $body['merchant_url']['success'] === route('api.checkout.callback')
                && $body['merchant_url']['cancel'] === route('api.checkout.callback')
                && $body['merchant_url']['failure'] === route('api.checkout.callback');
        });
    }

    /** @test */
    public function tamara_disabled_new_initiation_is_422_with_no_provider_call(): void
    {
        config(['payment.gateways.tamara.enabled' => false]);

        Http::preventStrayRequests();

        $order = $this->makeOrder(100.0, 'SAR');

        $response = $this->initiate($order, 100.0);

        $this->assertSame(422, $response->status());
        $this->assertFalse((bool) $response->getData()->success);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'tamara',
        ]);
    }

    /** @test */
    public function tamara_unsupported_order_currency_fails_closed_with_no_provider_call(): void
    {
        // Tamara's documented currency enum is SAR|AED|BHD|KWD|OMR — USD
        // orders must be refused by the registry gate BEFORE any Tamara call
        // exists.
        $order = $this->makeOrder(13.255, 'USD');

        Http::preventStrayRequests();

        $response = $this->initiate($order, 13.255);

        $this->assertSame(422, $response->status());
        $this->assertFalse((bool) $response->getData()->success);
        $this->assertStringContainsString('USD', (string) $response->getData()->message);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'tamara',
        ]);
    }

    // -----------------------------------------------------------------
    // Callback: the Tamara redirect contract (order_id fallback)
    // -----------------------------------------------------------------

    private function pendingOrderTxn(Order $order, string $ref): Transaction
    {
        return $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'tamara',
            'status' => 'pending',
            'amount' => (float) $order->total_price,
            'currency' => strtoupper((string) $order->currency_code),
            'gateway_transaction_id' => $ref,
            'invoice_id' => $ref,
        ]);
    }

    /** @test */
    public function callback_accepts_the_order_id_reference_and_completes_via_server_verification(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->pendingOrderTxn($order, '870e6c88-cb1-4a44-9ec0-7f7b8ed65e0c');

        // Server-side re-verification decides — the redirect is only a
        // lookup hint (order_id is the Tamara-echo fallback; param name
        // remains UNVERIFIED until a live sandbox redirect).
        $this->mockVerify(
            'tamara',
            $txn->gateway_transaction_id,
            1,
            $this->paidResult($txn->gateway_transaction_id, 100.0, 'SAR'),
        );

        $response = $this->get('/api/v1/general/checkout/callback?order_id=' . $txn->gateway_transaction_id . '&type=mobile');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    /** @test */
    public function callback_paymentId_keeps_precedence_over_the_tamara_fallbacks(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->pendingOrderTxn($order, 'PAY-REG-1');

        $this->mockVerify(
            'tamara',
            'PAY-REG-1',
            1,
            $this->paidResult('PAY-REG-1', 100.0, 'SAR'),
        );

        $response = $this->get('/api/v1/general/checkout/callback?paymentId=PAY-REG-1&orderId=870e6c88-legacy&type=mobile');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
    }

    /** @test */
    public function callback_without_any_reference_is_400(): void
    {
        $this->makeOrder(100.0, 'SAR');

        $response = $this->get('/api/v1/general/checkout/callback?type=mobile');

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    // -----------------------------------------------------------------
    // Capture-unresolved recovery invariant (D1-R)
    // -----------------------------------------------------------------

    /** @test */
    public function capture_unresolved_keeps_the_row_completable_and_a_later_webhook_completes(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->pendingOrderTxn($order, '870e6c88-recovery-4a44-9ec0-7f7b8ed65e0c');

        // 1) The buyer callback re-enters while the provider state is
        //    unreadable/pending: NON-TERMINAL pending — order/inventory/
        //    promotions untouched.
        // 2) The later `order_authorised` webhook re-verifies; Tamara now
        //    reports authorised money — completion runs exactly once.
        $this->mockVerify(
            'tamara',
            $txn->gateway_transaction_id,
            2,
            $this->pendingResult($txn->gateway_transaction_id, 100.0, 'SAR'),
            $this->paidResult($txn->gateway_transaction_id, 100.0, 'SAR'),
        );

        $callback = $this->get('/api/v1/general/checkout/callback?order_id=' . $txn->gateway_transaction_id . '&type=mobile');
        $callback->assertStatus(200);

        $this->assertSame('pending', $txn->fresh()->status, 'unread provider truth must stay pending (D1-R)');
        $this->assertSame('pending', $order->fresh()->status);
        // Completable: the idempotency token still allows a later commit.
        $this->assertNull($txn->fresh()->idempotency_key);

        $webhookResponse = $this->postTamaraWebhook($txn->gateway_transaction_id, 'order_authorised', [
            'status' => 'authorised',
        ]);

        $webhookResponse->assertStatus(200);
        $webhookResponse->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
        $this->assertNotNull($txn->fresh()->idempotency_key);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    // -----------------------------------------------------------------
    // Adapter refunds via the PaymentRefundService authority (DB-backed;
    // unit tests cover only the money-state fences).
    // -----------------------------------------------------------------

    private function paidTamaraTxn(Order $order, string $ref): Transaction
    {
        $txn = new Transaction([
            'user_id' => $order->user_id,
            'payment_method' => 'tamara',
            'status' => 'paid',
            'amount' => (float) $order->total_price,
            'currency' => strtoupper((string) $order->currency_code),
            'gateway_transaction_id' => $ref,
            'invoice_id' => $ref,
            'paid_at' => now(),
        ]);
        $order->transactions()->save($txn);

        return $txn;
    }

    /** @test */
    public function refund_of_captured_money_posts_the_simplified_refund_and_flips_the_order(): void
    {
        $ref = '870e6c88-ref1-4a44-9ec0-7f7b8ed65e0c';

        Http::fake([
            'api-sandbox.tamara.co/orders/' . $ref => Http::response([
                'order_id' => $ref,
                'status' => 'fully_captured',
                'total_amount' => ['amount' => 100.0, 'currency' => 'SAR'],
                'amounts' => [
                    'captured_amount' => ['amount' => 100.0, 'currency' => 'SAR'],
                    'refunded_amount' => ['amount' => 0, 'currency' => 'SAR'],
                    'canceled_amount' => ['amount' => 0, 'currency' => 'SAR'],
                ],
            ], 200),
            'api-sandbox.tamara.co/payments/simplified-refund/' . $ref => Http::response([
                'refund_id' => 'refund_flow_1',
                'status' => 'INITIATED',
            ], 200),
        ]);

        $order = $this->makeOrder(100.0, 'SAR');
        $order->forceFill([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ])->save();
        $txn = $this->paidTamaraTxn($order, $ref);

        $summary = app(PaymentRefundService::class)->refund(
            $order->fresh(),
            100.0,
            'Customer changed mind',
            'tamara-flow-refund-key-1',
            (int) $order->user_id,
        );

        $this->assertTrue($summary['full_refund']);
        $this->assertSame(100.0, $summary['refunded_amount']);
        $this->assertSame('SAR', $summary['refunded_currency']);
        $this->assertSame($ref, $summary['provider_ref']);
        $this->assertFalse($summary['idempotent_replay']);

        $this->assertSame('refunded', $txn->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_REFUNDED, $order->fresh()->payment_status);

        // Ledger replay: the same key returns the recorded summary WITHOUT a
        // second provider call.
        Http::fake([
            'api-sandbox.tamara.co/payments/simplified-refund/' . $ref => Http::response([], 500),
        ]);

        $replay = app(PaymentRefundService::class)->refund(
            $order->fresh(),
            100.0,
            'Customer changed mind',
            'tamara-flow-refund-key-1',
            (int) $order->user_id,
        );

        $this->assertTrue($replay['idempotent_replay']);
        $this->assertSame($ref, $replay['provider_ref']);
    }

    /** @test */
    public function refund_of_authorised_uncaptured_money_converts_to_the_provider_cancel_void(): void
    {
        // A locally-completed Tamara order whose provider order is still
        // ONLY authorised (the money obligation completed the order; no
        // capture yet): a full refund must VOID the authorisation through
        // POST /orders/{id}/cancel — never a simplified refund.
        $ref = '870e6c88-void-4a44-9ec0-7f7b8ed65e0c';

        Http::fake([
            'api-sandbox.tamara.co/orders/' . $ref => Http::response([
                'order_id' => $ref,
                'status' => 'authorised',
                'total_amount' => ['amount' => 50.0, 'currency' => 'SAR'],
                'amounts' => [
                    'captured_amount' => ['amount' => 0, 'currency' => 'SAR'],
                    'refunded_amount' => ['amount' => 0, 'currency' => 'SAR'],
                    'canceled_amount' => ['amount' => 0, 'currency' => 'SAR'],
                ],
            ], 200),
            'api-sandbox.tamara.co/orders/' . $ref . '/cancel' => Http::response([
                'order_id' => $ref,
                'canceled_amount' => ['amount' => 50.0, 'currency' => 'SAR'],
                'status' => 'canceled',
            ], 200),
        ]);

        $order = $this->makeOrder(50.0, 'SAR');
        $order->forceFill([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ])->save();
        $txn = $this->paidTamaraTxn($order, $ref);

        $summary = app(PaymentRefundService::class)->refund(
            $order->fresh(),
            50.0,
            null,
            'tamara-flow-void-key-1',
            (int) $order->user_id,
        );

        $this->assertTrue($summary['full_refund']);
        $this->assertSame(50.0, $summary['refunded_amount']);
        $this->assertSame($ref, $summary['provider_ref']);
        $this->assertFalse($summary['idempotent_replay']);

        $this->assertSame('refunded', $txn->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_REFUNDED, $order->fresh()->payment_status);

        // The void is recorded honestly as the provider state it is: a
        // cancellation of authorised money, never a labelled refund.
        $ledger = (array) ($txn->fresh()->gateway_response['_refunds'] ?? []);
        $this->assertNotEmpty($ledger);
        $this->assertSame('cancelled', $ledger[0]['provider_status']);
    }

    /** @test */
    public function partial_refund_of_authorised_uncaptured_money_is_refused(): void
    {
        // Doc constraint: cancel operates on the WHOLE authorised amount;
        // partial refunds exist only post-capture. The refund authority
        // surfaces the refusal as an exception (fail-closed rollback).
        $ref = '870e6c88-partial-void-000000-000000000000';

        Http::fake([
            'api-sandbox.tamara.co/orders/' . $ref => Http::response([
                'order_id' => $ref,
                'status' => 'authorised',
                'total_amount' => ['amount' => 50.0, 'currency' => 'SAR'],
                'amounts' => [
                    'captured_amount' => ['amount' => 0, 'currency' => 'SAR'],
                    'refunded_amount' => ['amount' => 0, 'currency' => 'SAR'],
                    'canceled_amount' => ['amount' => 0, 'currency' => 'SAR'],
                ],
            ], 200),
        ]);

        $order = $this->makeOrder(50.0, 'SAR');
        $order->forceFill([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ])->save();
        $txn = $this->paidTamaraTxn($order, $ref);

        try {
            app(PaymentRefundService::class)->refund(
                $order->fresh(),
                20.0,
                null,
                'tamara-flow-partial-void-key-1',
                (int) $order->user_id,
            );

            $this->fail('Partial refund of authorised-uncaptured money must be refused');
        } catch (\RuntimeException $refusal) {
            // The refusal must leave the txn untouched: the authority
            // rolls back before any money semantics land.
            $this->assertSame('paid', $txn->fresh()->status);
            $this->assertArrayNotHasKey('_refunds', (array) $txn->fresh()->gateway_response);
        }
    }
}

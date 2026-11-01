<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\CheckoutTotals;
use App\DTOs\GatewayResult;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Gateway\TabbyGateway;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentCheckoutHandler;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentRefundService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * Tabby gateway flow: initiation through the canonical PaymentCheckoutHandler,
 * the OrderController payment_id callback fallback (Tabby HPP redirects
 * append `payment_id`), the capture-unresolved recovery invariant, and
 * DB-backed adapter refunds (the unit tests cover only the pre-lookup
 * refund guards).
 *
 * No live credentials: every Tabby interaction runs through Http::fake(),
 * gateway re-verification runs through a mocked PaymentGatewayFactory, and
 * Tabby's network is never touched.
 *
 * STATUS: DB-backed (RefreshDatabase) — NOT RUNNABLE in the environment
 * where MySQL is unreachable (see docs/audits/tabby-payment-integration-audit.md
 * §Tests). Runnable where a MySQL test database exists:
 *
 *   php vendor\phpunit\phpunit\phpunit tests\Feature\Payment\TabbyGatewayFlowTest.php
 */
class TabbyGatewayFlowTest extends CurrencyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Any provider URL not covered by a test's Http::fake() must fail
        // loudly instead of attempting a real network call (e.g. a
        // precedence regression falling back to myfatoorah verification).
        Http::preventStrayRequests();

        config(['payment.gateways.tabby.class' => TabbyGateway::class]);
        config(['payment.gateways.tabby.enabled' => true]);
        config(['payment.gateways.tabby.secret_key' => 'sk_test_tabby_flow_secret']);
        config(['payment.gateways.tabby.base_url' => 'https://api.tabby.ai/api/v2']);
        config(['payment.gateways.tabby.merchant_code' => 'tabby_merchant_flow']);
        config(['payment.gateways.tabby.webhook_header' => 'x-tabby-signature']);
        config(['payment.gateways.tabby.webhook_secret' => 'flow_webhook_shared_secret']);
        config(['payment.gateways.tabby.supported_currencies' => ['AED', 'SAR']]);
        config(['payment.gateways.tabby.capture_attempts' => 2]);
        config(['payment.gateways.tabby.capture_retry_delay_ms' => 0]);
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
        $this->seedCurrencyData(); // USD + KWD + SAR (seeded set) — SAR is Tabby-supported

        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', $currency)->firstOrFail());
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', $currency)->firstOrFail());

        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Tabby Flow Customer',
                'user_phone' => '0555000001',
                'user_email' => $customer->email,
                'address' => '123 Tabby Flow Street',
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
            ->handleOnlinePayment($this->initiationRequest($order), $order, $amount, 'tabby');
    }

    /**
     * Mock gateway re-verification behind the factory seam. Variable
     * results return sequentially (first call → first result).
     */
    private function mockVerify(string $gateway, string $ref, GatewayResult ...$results): void
    {
        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->times(count($results))
            ->with($ref)
            ->andReturn(...$results);

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')
            ->times(count($results))
            ->with($gateway)
            ->andReturn($mockGateway);

        // Bind BEFORE the first request: the routing cache resolves the
        // controller per in-process call, and a factory instance bound
        // later never reaches it (see TapWebhookTest note).
        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);
    }

    private function pendingPendingResult(string $ref, float $amount, string $currency): GatewayResult
    {
        return new GatewayResult(
            success: false,
            gatewayTransactionId: $ref,
            amount: $amount,
            currency: $currency,
            status: 'pending',
            rawResponse: ['_capture' => ['reference_id' => 'capture-' . $ref, 'attempts' => 3, 'outcome' => 'unresolved']],
        );
    }

    private function paidResult(string $ref, float $amount, string $currency): GatewayResult
    {
        return new GatewayResult(
            success: true,
            gatewayTransactionId: $ref,
            amount: $amount,
            currency: $currency,
            status: 'paid',
            rawResponse: ['id' => $ref, 'status' => 'CLOSED', 'amount' => $amount, 'currency' => $currency, 'captures' => [['id' => 'cap_1']]],
        );
    }

    private function failedResult(string $ref, float $amount, string $currency): GatewayResult
    {
        return new GatewayResult(
            success: false,
            gatewayTransactionId: $ref,
            amount: $amount,
            currency: $currency,
            status: 'failed',
            errorMessage: __('message.ERROR.PAYMENT_PROVIDER_DECLINED'),
        );
    }

    // -----------------------------------------------------------------
    // Initiation (canonical handler path)
    // -----------------------------------------------------------------

    /** @test */
    public function tabby_initiation_creates_the_pending_transaction_and_returns_the_redirect(): void
    {
        Http::fake([
            'api.tabby.ai/api/v2/checkout' => Http::response([
                'id' => 'sess_flow_1',
                'status' => 'created',
                'payment' => [
                    'id' => 'pay_flow_1',
                    'status' => 'CREATED',
                    'amount' => '100.00',
                    'currency' => 'SAR',
                    'buyer' => ['name' => 'Echoed', 'email' => 'echoed@example.com'],
                ],
                'configuration' => [
                    'available_products' => ['installments' => [['web_url' => 'https://checkout.tabby.ai/payflow']]],
                    'products' => ['installments' => ['type' => 'installments', 'is_available' => true]],
                ],
            ], 200),
        ]);

        $order = $this->makeOrder(100.0, 'SAR');

        $response = $this->initiate($order, 100.0);

        $this->assertSame(200, $response->status());
        $this->assertTrue((bool) $response->getData()->success);
        $this->assertSame('https://checkout.tabby.ai/payflow', $response->getData()->data->url);

        $txn = Transaction::query()->where('order_id', $order->id)->where('payment_method', 'tabby')->first();
        $this->assertNotNull($txn);
        $this->assertSame('pending', $txn->status);
        $this->assertSame(100.0, (float) $txn->amount);
        $this->assertSame('SAR', $txn->currency);
        $this->assertSame('pay_flow_1', $txn->gateway_transaction_id);

        // Provider PII is stripped at the source before persistence.
        $stored = $txn->gateway_response;
        $this->assertArrayNotHasKey('payment', (array) $stored);
        foreach ((array) ($stored['_payload'] ?? $stored) as $chunk) {
            $this->assertStringNotContainsString('Echoed', json_encode($chunk));
            $this->assertStringNotContainsString('echoed@example.com', json_encode($chunk));
        }

        Http::assertSent(function ($request) use ($order) {
            $body = $request->data();
            $payment = $body['payment'] ?? [];

            return $body['payment']['amount'] === '100.00'
                && $payment['currency'] === 'SAR'
                && $payment['order']['reference_id'] === (string) $order->id
                && $payment['buyer']['phone'] === '0555000001'
                && $payment['buyer_history']['registered_since'] !== ''
                // Session `lang` contract (docs session-payload-model) is the
                // enum ar|en — this suite boots with the app's `ar` locale.
                && in_array($body['lang'], ['ar', 'en'], true)
                && $body['merchant_code'] === 'tabby_merchant_flow'
                && $body['merchant_urls']['success'] === route('api.checkout.callback')
                && $body['merchant_urls']['cancel'] === route('api.checkout.callback')
                && $body['merchant_urls']['failure'] === route('api.checkout.callback');
        });
    }

    /** @test */
    public function tabby_disabled_new_initiation_is_422_with_no_provider_call(): void
    {
        config(['payment.gateways.tabby.enabled' => false]);

        Http::preventStrayRequests();

        $order = $this->makeOrder(100.0, 'SAR');

        $response = $this->initiate($order, 100.0);

        $this->assertSame(422, $response->status());
        $this->assertFalse((bool) $response->getData()->success);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'tabby',
        ]);
    }

    /** @test */
    public function tabby_unsupported_order_currency_fails_closed_with_no_provider_call(): void
    {
        // Tabby's documented currency enum is AED|SAR — KWD orders must be
        // refused by the registry gate BEFORE any Tabby call exists.
        $order = $this->makeOrder(13.255, 'KWD');

        Http::preventStrayRequests();

        $response = $this->initiate($order, 13.255);

        $this->assertSame(422, $response->status());
        $this->assertFalse((bool) $response->getData()->success);
        $this->assertStringContainsString('KWD', (string) $response->getData()->message);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'tabby',
        ]);
    }

    // -----------------------------------------------------------------
    // Callback: the Tabby redirect contract (payment_id fallback)
    // -----------------------------------------------------------------

    private function pendingOrderTxn(Order $order, string $ref): Transaction
    {
        return $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'tabby',
            'status' => 'pending',
            'amount' => (float) $order->total_price,
            'currency' => strtoupper((string) $order->currency_code),
            'gateway_transaction_id' => $ref,
            'invoice_id' => $ref,
        ]);
    }

    /** @test */
    public function callback_accepts_payment_id_and_completes_via_server_verification(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->pendingOrderTxn($order, 'pay_cb_1');

        // Server-side re-verification decides — the redirect is only a
        // lookup hint.
        $this->mockVerify('tabby', 'pay_cb_1', $this->paidResult('pay_cb_1', 100.0, 'SAR'));

        $response = $this->get('/api/v1/general/checkout/callback?payment_id=pay_cb_1&type=mobile');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    /** @test */
    public function callback_paymentId_keeps_precedence_over_payment_id(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->pendingOrderTxn($order, 'PAY-REG-1');

        $this->mockVerify('tabby', 'PAY-REG-1', $this->paidResult('PAY-REG-1', 100.0, 'SAR'));

        $response = $this->get('/api/v1/general/checkout/callback?paymentId=PAY-REG-1&payment_id=pay_other&type=mobile');

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
        $txn = $this->pendingOrderTxn($order, 'pay_recovery_1');

        // 1) The buyer callback re-enters while the capture is unresolved:
        //    NON-TERMINAL pending — order/inventory/promotions untouched.
        // 2) The later `authorized` webhook re-verifies; Tabby now reports
        //    CLOSED + captures — completion runs exactly once.
        $this->mockVerify(
            'tabby',
            'pay_recovery_1',
            $this->pendingPendingResult('pay_recovery_1', 100.0, 'SAR'),
            $this->paidResult('pay_recovery_1', 100.0, 'SAR'),
        );

        $callback = $this->get('/api/v1/general/checkout/callback?payment_id=pay_recovery_1&type=mobile');
        $callback->assertStatus(200);

        $this->assertSame('pending', $txn->fresh()->status, 'unresolved capture must stay pending (D1-R)');
        $this->assertSame('pending', $order->fresh()->status);
        // Completable: the idempotency token still allows a later commit.
        $this->assertNull($txn->fresh()->idempotency_key);

        $webhookResponse = $this->call(
            'POST',
            '/api/v1/general/checkout/webhooks/tabby',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TABBY_SIGNATURE' => 'flow_webhook_shared_secret'],
            (string) json_encode([
                'id' => 'pay_recovery_1',
                'status' => 'authorized',
                'amount' => 100.0,
                'currency' => 'SAR',
                'captures' => [],
            ]),
        );

        $webhookResponse->assertStatus(200);
        $webhookResponse->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
        $this->assertNotNull($txn->fresh()->idempotency_key);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    // -----------------------------------------------------------------
    // Adapter refunds (DB-backed; unit tests cover pre-lookup guards only)
    // -----------------------------------------------------------------

    private function paidTabbyTxn(Order $order, string $ref): Transaction
    {
        $txn = new Transaction([
            'user_id' => $order->user_id,
            'payment_method' => 'tabby',
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
    public function adapter_refund_targets_the_latest_transaction_and_sends_the_idempotent_reference(): void
    {
        Http::fake([
            'api.tabby.ai/api/v2/payments/pay_new_1/refunds' => Http::response([
                'id' => 'refund_flow_1',
                'amount' => '50.00',
                'reference_id' => 'refund-replace-me-50.00',
            ], 200),
        ]);

        $order = $this->makeOrder(50.0, 'SAR');
        $this->paidTabbyTxn($order, 'pay_old_1'); // superseded by the newest
        $this->paidTabbyTxn($order, 'pay_new_1');

        $adapter = app(TabbyGateway::class);
        $result = $adapter->refund($order->fresh(), 50.0, 'Customer changed mind');

        $this->assertTrue($result->success);
        $this->assertSame('refund_flow_1', $result->gatewayTransactionId);
        $this->assertSame(50.0, $result->amount);
        $this->assertSame('SAR', $result->currency);

        Http::assertSent(function ($request) use ($order) {
            $body = $request->data();

            return $body['amount'] === '50.00'
                // Constant per (order, amount) — retries never double-refund.
                && $body['reference_id'] === 'refund-' . $order->id . '-50.00'
                && $body['reason'] === 'Customer changed mind';
        });
    }

    /** @test */
    public function adapter_refund_fails_closed_when_tabby_has_not_confirmed(): void
    {
        Http::fake([
            'api.tabby.ai/api/v2/payments/pay_ref_2/refunds' => Http::response([
                'status' => 'succeeded', // no refund `id` echo — ambiguous
            ], 200),
        ]);

        $order = $this->makeOrder(50.0, 'SAR');
        $this->paidTabbyTxn($order, 'pay_ref_2');

        $result = app(TabbyGateway::class)->refund($order->fresh(), 50.0, null);

        $this->assertFalse($result->success);
        $this->assertSame('Refund not confirmed by gateway', $result->errorMessage);
    }

    /** @test */
    public function adapter_refund_fails_closed_on_provider_error(): void
    {
        Http::fake([
            'api.tabby.ai/api/v2/payments/pay_ref_3/refunds' => Http::response([
                'errorType' => 'ValidationError',
                'errors' => ['refund amount exceeds captured'],
            ], 400),
        ]);

        $order = $this->makeOrder(50.0, 'SAR');
        $this->paidTabbyTxn($order, 'pay_ref_3');

        $result = app(TabbyGateway::class)->refund($order->fresh(), 50.0, null);

        $this->assertFalse($result->success);
        // errorMessageFrom echoes Tabby's documented error-envelope text.
        $this->assertSame('refund amount exceeds captured', $result->errorMessage);
    }

    /** @test */
    public function full_refund_through_the_refund_authority_records_the_ledger_and_frees_the_order(): void
    {
        Http::fake([
            'api.tabby.ai/api/v2/payments/pay_ref_4/refunds' => Http::response([
                'id' => 'refund_flow_4',
                'amount' => '100.00',
            ], 200),
        ]);

        $order = $this->makeOrder(100.0, 'SAR');
        $order->forceFill([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ])->save();
        $txn = $this->paidTabbyTxn($order, 'pay_ref_4');

        $summary = app(PaymentRefundService::class)->refund(
            $order->fresh(),
            100.0,
            'Customer changed mind',
            'tabby-refund-key-1',
            (int) $order->user_id,
        );

        $this->assertTrue($summary['full_refund']);
        $this->assertSame(100.0, $summary['refunded_amount']);
        $this->assertSame('SAR', $summary['refunded_currency']);
        $this->assertSame('refund_flow_4', $summary['provider_ref']);
        $this->assertFalse($summary['idempotent_replay']);

        $this->assertSame('refunded', $txn->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_REFUNDED, $order->fresh()->payment_status);

        // Ledger replay: the same key returns the recorded summary WITHOUT a
        // second provider call.
        Http::fake([
            'api.tabby.ai/api/v2/payments/pay_ref_4/refunds' => Http::response([], 500),
        ]);

        $replay = app(PaymentRefundService::class)->refund(
            $order->fresh(),
            100.0,
            'Customer changed mind',
            'tabby-refund-key-1',
            (int) $order->user_id,
        );

        $this->assertTrue($replay['idempotent_replay']);
        $this->assertSame('refund_flow_4', $replay['provider_ref']);
    }
}

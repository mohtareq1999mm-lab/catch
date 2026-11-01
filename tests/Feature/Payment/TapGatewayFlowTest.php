<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\CheckoutTotals;
use App\DTOs\GatewayResult;
use App\Events\PaymentSucceeded;
use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentCheckoutHandler;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentRefundService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * Tap gateway flow: initiation through the canonical PaymentCheckoutHandler,
 * the OrderController tap_id callback fallback, and DB-backed adapter
 * refunds (the unit tests cover only the pre-lookup refund guards).
 *
 * No live credentials: every Tap interaction runs through Http::fake() and
 * gateway re-verification runs through a mocked PaymentGatewayFactory.
 */
class TapGatewayFlowTest extends CurrencyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Any provider URL not covered by a test's Http::fake() must fail
        // loudly instead of attempting a real network call (e.g. a
        // precedence regression falling back to myfatoorah verification).
        \Illuminate\Support\Facades\Http::preventStrayRequests();

        config(['payment.gateways.tap.class' => \App\Services\Gateway\TapGateway::class]);
        config(['payment.gateways.tap.enabled' => true]);
        config(['payment.gateways.tap.secret_key' => 'sk_test_flow_secret']);
        config(['payment.gateways.tap.base_url' => 'https://api.tap.company/v2']);
        config(['payment.gateways.tap.source_id' => 'src_all']);
        config(['payment.gateways.tap.supported_currencies' => ['AED', 'BHD', 'EGP', 'EUR', 'GBP', 'JOD', 'KWD', 'OMR', 'QAR', 'SAR', 'USD']]);
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
        $this->seedCurrencyData();

        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', $currency)->firstOrFail());
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', $currency)->firstOrFail());

        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Tap Flow Customer',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 Tap Flow Street',
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
            ->handleOnlinePayment($this->initiationRequest($order), $order, $amount, 'tap');
    }

    /**
     * Mock gateway re-verification behind the factory seam.
     */
    private function mockVerify(string $gateway, string $ref, GatewayResult $result, int $times = 1): void
    {
        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->times($times)
            ->with($ref)
            ->andReturn($result);

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')
            ->times($times)
            ->with($gateway)
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);
    }

    // -----------------------------------------------------------------
    // Initiation (canonical handler path)
    // -----------------------------------------------------------------

    /** @test */
    public function tap_initiation_creates_the_pending_transaction_and_returns_the_redirect(): void
    {
        Http::fake([
            'api.tap.company/v2/charges' => Http::response([
                'id' => 'chg_flow_1',
                'status' => 'INITIATED',
                'amount' => 13.255,
                'currency' => 'KWD',
                'transaction' => ['url' => 'https://gate.tap.company/redirect/chg_flow_1'],
            ], 200),
        ]);

        $order = $this->makeOrder(13.255, 'KWD');

        $response = $this->initiate($order, 13.255);

        $this->assertSame(200, $response->status());
        $this->assertTrue((bool) $response->getData()->success);
        $this->assertSame('https://gate.tap.company/redirect/chg_flow_1', $response->getData()->data->url);

        $txn = Transaction::query()->where('order_id', $order->id)->where('payment_method', 'tap')->first();
        $this->assertNotNull($txn);
        $this->assertSame('pending', $txn->status);
        $this->assertSame(13.255, (float) $txn->amount);
        $this->assertSame('KWD', $txn->currency);
        $this->assertSame('chg_flow_1', $txn->gateway_transaction_id);
        $this->assertSame('chg_flow_1', $txn->invoice_id);

        // Provider PII is stripped at the source; the webhook URL is bound
        // for callback-type resolution.
        $stored = $txn->gateway_response;
        $this->assertArrayNotHasKey('customer', $stored);
        $this->assertArrayNotHasKey('card', $stored);

        Http::assertSent(function ($request) use ($order) {
            $body = $request->data();

            return $body['amount'] === 13.255
                && $body['currency'] === 'KWD'
                && $body['reference']['order'] === (string) $order->id
                && $body['reference']['idempotent'] === 'order-' . $order->id
                && $body['post']['url'] === route('api.checkout.webhooks.tap')
                && $body['redirect']['url'] === route('api.checkout.callback');
        });
    }

    /** @test */
    public function tap_disabled_new_initiation_is_422_with_no_provider_call(): void
    {
        config(['payment.gateways.tap.enabled' => false]);

        Http::preventStrayRequests();

        $order = $this->makeOrder(13.255, 'KWD');

        $response = $this->initiate($order, 13.255);

        $this->assertSame(422, $response->status());
        $this->assertFalse((bool) $response->getData()->success);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'tap',
        ]);
    }

    /** @test */
    public function tap_missing_credentials_fail_closed_with_no_provider_call(): void
    {
        config(['payment.gateways.tap.secret_key' => null]);

        Http::preventStrayRequests();

        $order = $this->makeOrder(13.255, 'KWD');

        $response = $this->initiate($order, 13.255);

        $this->assertSame(422, $response->status());
        $this->assertFalse((bool) $response->getData()->success);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'tap',
        ]);
    }

    /** @test */
    public function tap_unsupported_order_currency_fails_closed_with_no_provider_call(): void
    {
        config(['payment.gateways.tap.supported_currencies' => ['KWD']]);

        Http::preventStrayRequests();

        $order = $this->makeOrder(100.0, 'SAR');

        $response = $this->initiate($order, 100.0);

        $this->assertSame(422, $response->status());
        $this->assertFalse((bool) $response->getData()->success);
        $this->assertStringContainsString('SAR', (string) $response->getData()->message);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'tap',
        ]);
    }

    // -----------------------------------------------------------------
    // Callback: the Tap redirect contract (tap_id)
    // -----------------------------------------------------------------

    /** @test */
    public function callback_accepts_tap_id_and_completes_via_server_verification(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'USD');
        $txn = $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'tap',
            'status' => 'pending',
            'amount' => 100.0,
            'currency' => 'USD',
            'gateway_transaction_id' => 'chg_cb_1',
            'invoice_id' => 'chg_cb_1',
        ]);

        // Server-side re-verification decides the outcome — the redirect
        // itself is only a lookup hint.
        $this->mockVerify('tap', 'chg_cb_1', new GatewayResult(
            success: true,
            gatewayTransactionId: 'chg_cb_1',
            amount: 100.0,
            currency: 'USD',
            status: 'paid',
            rawResponse: ['id' => 'chg_cb_1', 'status' => 'CAPTURED', 'amount' => 100.0, 'currency' => 'USD'],
        ));

        $response = $this->get('/api/v1/general/checkout/callback?tap_id=chg_cb_1&type=mobile');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    /** @test */
    public function callback_paymentId_keeps_precedence_over_tap_id(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'USD');
        $txn = $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'tap',
            'status' => 'pending',
            'amount' => 100.0,
            'currency' => 'USD',
            'gateway_transaction_id' => 'PAY-REG-1',
            'invoice_id' => 'PAY-REG-1',
        ]);

        $this->mockVerify('tap', 'PAY-REG-1', new GatewayResult(
            success: true,
            gatewayTransactionId: 'PAY-REG-1',
            amount: 100.0,
            currency: 'USD',
            status: 'paid',
            rawResponse: ['id' => 'PAY-REG-1', 'status' => 'CAPTURED', 'amount' => 100.0, 'currency' => 'USD'],
        ));

        // Both params present: the legacy paymentId must win (existing
        // gateway behavior is unchanged).
        $response = $this->get('/api/v1/general/checkout/callback?paymentId=PAY-REG-1&tap_id=chg_cb_other&type=mobile');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
    }

    /** @test */
    public function callback_without_any_reference_is_400(): void
    {
        $this->makeOrder(100.0, 'USD');

        $response = $this->get('/api/v1/general/checkout/callback?type=mobile');

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    // -----------------------------------------------------------------
    // Adapter refunds (DB-backed; unit tests only cover pre-lookup guards)
    // -----------------------------------------------------------------

    private function paidTapTxn(Order $order, string $ref, string $createdAtOffset = '-1 hour'): Transaction
    {
        $txn = new Transaction([
            'user_id' => $order->user_id,
            'payment_method' => 'tap',
            'status' => 'paid',
            'amount' => (float) $order->total_price,
            'currency' => strtoupper((string) $order->currency_code),
            'gateway_transaction_id' => $ref,
            'invoice_id' => $ref,
            'paid_at' => now(),
        ]);
        $txn->created_at = now()->modify($createdAtOffset);
        $order->transactions()->save($txn);

        return $txn;
    }

    /** @test */
    public function adapter_refund_targets_the_latest_transaction_and_maps_the_reason(): void
    {
        Http::fake([
            'api.tap.company/v2/refunds' => Http::response([
                'id' => 're_flow_1',
                'status' => 'REFUNDED',
                'amount' => 13.255,
                'currency' => 'KWD',
                'charge_id' => 'chg_new_1',
            ], 200),
        ]);

        $order = $this->makeOrder(13.255, 'KWD');
        $this->paidTapTxn($order, 'chg_old_1', '-1 day');
        $this->paidTapTxn($order, 'chg_new_1');

        $adapter = app(\App\Services\Gateway\TapGateway::class);
        $result = $adapter->refund($order->fresh(), 13.255, 'Customer changed mind');

        $this->assertTrue($result->success);
        $this->assertSame('re_flow_1', $result->gatewayTransactionId);
        $this->assertSame(13.255, $result->amount);
        $this->assertSame('KWD', $result->currency);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['charge_id'] === 'chg_new_1'
                && $body['amount'] === 13.255
                && $body['currency'] === 'KWD'
                && $body['reason'] === 'requested_by_customer'
                && $body['metadata']['order_id'] !== ''
                && $body['metadata']['reason'] === 'Customer changed mind';
        });
    }

    /** @test */
    public function adapter_refund_fails_closed_when_tap_has_not_confirmed(): void
    {
        Http::fake([
            'api.tap.company/v2/refunds' => Http::response([
                'id' => 're_flow_2',
                'status' => 'PENDING',
                'amount' => 13.255,
                'currency' => 'KWD',
                'charge_id' => 'chg_ref_2',
            ], 200),
        ]);

        $order = $this->makeOrder(13.255, 'KWD');
        $this->paidTapTxn($order, 'chg_ref_2');

        $result = app(\App\Services\Gateway\TapGateway::class)->refund($order->fresh(), 13.255, null);

        $this->assertFalse($result->success);
        $this->assertSame('Refund not confirmed by gateway', $result->errorMessage);
    }

    /** @test */
    public function adapter_refund_fails_closed_on_provider_error(): void
    {
        Http::fake([
            'api.tap.company/v2/refunds' => Http::response(['message' => 'charge not refundable'], 400),
        ]);

        $order = $this->makeOrder(13.255, 'KWD');
        $this->paidTapTxn($order, 'chg_ref_3');

        $result = app(\App\Services\Gateway\TapGateway::class)->refund($order->fresh(), 13.255, null);

        $this->assertFalse($result->success);
        // errorMessageFrom prefers the provider's own `message` field over
        // the HTTP-status fallback — assert the provider message survived
        // sanitization instead of the generic fallback.
        $this->assertSame('charge not refundable', $result->errorMessage);
    }

    /** @test */
    public function full_refund_through_the_refund_authority_records_the_ledger_and_frees_the_order(): void
    {
        Http::fake([
            'api.tap.company/v2/refunds' => Http::response([
                'id' => 're_flow_4',
                'status' => 'REFUNDED',
                'amount' => 13.255,
                'currency' => 'KWD',
                'charge_id' => 'chg_ref_4',
            ], 200),
        ]);

        $order = $this->makeOrder(13.255, 'KWD');
        // Refund preconditions (PaymentRefundService L92-94): the locked
        // order must carry the canonical completed/payment-success constants —
        // raw string literals risk drifting from the model's constants.
        $order->forceFill([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ])->save();
        $txn = $this->paidTapTxn($order, 'chg_ref_4');
        $txn->update(['status' => 'paid']);

        $summary = app(PaymentRefundService::class)->refund(
            $order->fresh(),
            13.255,
            'Customer changed mind',
            'refund-key-1',
            (int) $order->user_id,
        );

        $this->assertTrue($summary['full_refund']);
        $this->assertSame(13.255, $summary['refunded_amount']);
        $this->assertSame('KWD', $summary['refunded_currency']);
        $this->assertSame('re_flow_4', $summary['provider_ref']);
        $this->assertFalse($summary['idempotent_replay']);

        // Full refund side effects: transaction refunded, order freed.
        $this->assertSame('refunded', $txn->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_REFUNDED, $order->fresh()->payment_status);

        // Ledger replay: repeating the same key returns the original
        // summary WITHOUT a second provider call.
        Http::fake([
            'api.tap.company/v2/refunds' => Http::response([], 500),
        ]);

        $replay = app(PaymentRefundService::class)->refund(
            $order->fresh(),
            13.255,
            'Customer changed mind',
            'refund-key-1',
            (int) $order->user_id,
        );

        $this->assertTrue($replay['idempotent_replay']);
        $this->assertSame('re_flow_4', $replay['provider_ref']);
    }
}

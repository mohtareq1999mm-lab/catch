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
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PayPalWebhookVerifier;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Transaction;
use Marvel\Enums\ItemType;
use Marvel\Enums\ProductType;
use Marvel\Enums\ShippingMethod;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * PHASE 12 (§41) — payment security fill-in verification.
 *
 * Mocked gateways, no live calls. Each test proves a fail-closed boundary:
 * forged callbacks/webhooks change nothing, unknown gateways create nothing,
 * cross-user access leaks nothing, extra input fields change nothing, and
 * responses carry no secrets.
 */
class PaymentSecurityTest extends CurrencyTestCase
{
    private const CALLBACK = '/api/v1/general/checkout/callback';

    private const ERROR_CALLBACK = '/api/v1/general/checkout/error-callback';

    private const STRIPE_URL = '/api/v1/general/checkout/webhooks/stripe';

    private const PAYPAL_URL = '/api/v1/general/checkout/webhooks/paypal';

    private const STRIPE_SECRET = 'whsec_test_secret_for_security';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCurrencyData();
        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());

        // Hermetic baseline regardless of the developer .env.
        config(['payment.gateways.myfatoorah.api_key' => 'test-key']);
        config(['payment.gateways.stripe.secret_key' => 'sk_test_fake']);
        config(['payment.gateways.stripe.webhook_secret' => self::STRIPE_SECRET]);
        config(['payment.gateways.stripe.supported_currencies' => ['USD', 'EUR', 'KWD', 'SAR', 'AED']]);
        config(['payment.gateways.paypal.mode' => 'sandbox']);
        config(['payment.gateways.paypal.client_id' => 'test-client-id']);
        config(['payment.gateways.paypal.client_secret' => 'test-client-secret']);
        config(['payment.gateways.paypal.webhook_id' => 'WH-TEST-123']);
        config(['payment.gateways.paypal.supported_currencies' => ['USD', 'EUR', 'KWD']]);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function makeKwdOrder(float $subtotal = 100.0): Order
    {
        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Security Customer',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 Security Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);
        $this->assertSame('KWD', $order->fresh()->currency_code);

        return $order->fresh();
    }

    private function makePendingTxn(Order $order, string $payId, string $gateway = 'myfatoorah'): Transaction
    {
        return $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => $gateway,
            'status' => 'pending',
            'amount' => (float) $order->total_price,
            'currency' => 'KWD',
            'gateway_transaction_id' => $payId,
            'invoice_id' => $payId,
        ]);
    }

    private function mockMyfatoorahTransport($checkInvoice = null): void
    {
        $mock = \Mockery::mock(\App\Services\General\MyfatoraService::class);

        if (is_callable($checkInvoice)) {
            $mock->shouldReceive('checkInvoice')->andReturnUsing($checkInvoice);
        } elseif ($checkInvoice !== null) {
            $mock->shouldReceive('checkInvoice')->andReturn($checkInvoice);
        } else {
            $mock->shouldIgnoreMissing();
        }

        $this->app->instance(\App\Services\General\MyfatoraService::class, $mock);
    }

    private function paidVerifyPayload(string $payId, float $amount): array
    {
        return [
            'IsSuccess' => true,
            'Message' => 'Paid',
            'Data' => [
                'InvoiceId' => $payId,
                'InvoiceStatus' => 'Paid',
                'InvoiceValue' => $amount,
                'DisplayCurrencyIso' => 'KWD',
                'InvoiceURL' => 'https://pay.example.test/' . $payId,
            ],
        ];
    }

    private function failedVerifyPayload(string $payId, float $amount): array
    {
        return [
            'IsSuccess' => true,
            'Message' => 'Failed',
            'Data' => [
                'InvoiceId' => $payId,
                'InvoiceStatus' => 'Failed',
                'InvoiceValue' => $amount,
                'DisplayCurrencyIso' => 'KWD',
            ],
        ];
    }

    private function mockFactoryVerify(string $gateway, string $ref, GatewayResult $result, ?int $times = null): void
    {
        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $expectation = $mockGateway->shouldReceive('verifyPayment')->with($ref)->andReturn($result);
        if ($times !== null) {
            $expectation->times($times);
        }

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryExpectation = $factoryMock->shouldReceive('make')->with($gateway)->andReturn($mockGateway);
        if ($times !== null) {
            $factoryExpectation->times($times);
        }

        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);
    }

    private function paidResult(string $ref, float $amount = 100.0): GatewayResult
    {
        return new GatewayResult(
            success: true,
            gatewayTransactionId: $ref,
            amount: $amount,
            currency: 'KWD',
            status: 'paid',
            rawResponse: ['id' => $ref, 'status' => 'paid', 'amount' => $amount, 'currency' => 'KWD'],
        );
    }

    private function stripeHeader(string $payload, string $secret): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        return "t={$timestamp},v1={$signature}";
    }

    private function stripeSessionPayload(string $eventId, string $sessionId): string
    {
        return (string) json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => $sessionId, 'object' => 'checkout.session']],
        ]);
    }

    private function postStripe(string $payload, ?string $secret = self::STRIPE_SECRET)
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($secret !== null) {
            $server['HTTP_STRIPE_SIGNATURE'] = $this->stripeHeader($payload, $secret);
        }

        return $this->call('POST', self::STRIPE_URL, [], [], [], $server, $payload);
    }

    private function postPayPal(array $body, bool $withHeaders = true)
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($withHeaders) {
            $server['HTTP_PAYPAL_TRANSMISSION_ID'] = 'transmission-test-id';
            $server['HTTP_PAYPAL_TRANSMISSION_TIME'] = gmdate('Y-m-d\TH:i:s\Z');
            $server['HTTP_PAYPAL_TRANSMISSION_SIG'] = 'test-signature';
            $server['HTTP_PAYPAL_CERT_URL'] = 'https://api.sandbox.paypal.com/certs/test';
            $server['HTTP_PAYPAL_AUTH_ALGO'] = 'SHA256withRSA';
        }

        return $this->call('POST', self::PAYPAL_URL, [], [], [], $server, (string) json_encode($body));
    }

    private function bindPayPalVerifier(array $response): void
    {
        $this->app->instance(
            PayPalWebhookVerifier::class,
            new PayPalWebhookVerifier(fn () => new SecFakePayPalVerifyClient($response)),
        );
    }

    private function paypalCompletedPayload(string $eventId, string $orderRef): array
    {
        return [
            'id' => $eventId,
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAP-' . $eventId,
                'status' => 'COMPLETED',
                'supplementary_data' => ['related_ids' => ['order_id' => $orderRef]],
                'amount' => ['currency_code' => 'KWD', 'value' => '100.00'],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // §41.1 — forged callback paymentIds
    // -----------------------------------------------------------------

    /** @test */
    public function forged_callback_payment_ids_are_400_with_no_transaction_change(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'PAY-SEC-1');
        $this->mockMyfatoorahTransport($this->paidVerifyPayload('PAY-SEC-1', 100.0));

        // None of these may reach verification: the controller allowlist is
        // ^[A-Za-z0-9\-_]+$ with a 191-char cap, fail-closed 400.
        $forged = [
            'sqli' => "' OR '1'='1",
            'xss' => '<script>alert(1)</script>',
            'overlong' => str_repeat('A', 200),
            'spaces' => 'PAY 1001',
            'path' => '../../etc/passwd',
            'semicolon' => 'PAY-1001;DROP TABLE users;--',
            'empty' => '',
        ];

        foreach ($forged as $label => $paymentId) {
            $response = $this->get(self::CALLBACK . '?paymentId=' . urlencode($paymentId) . '&type=mobile');

            $response->assertStatus(400, "Forged paymentId ({$label}) must be 400");
            $response->assertJsonPath('success', false);
        }

        // Error callback enforces the identical allowlist.
        $this->get(self::ERROR_CALLBACK . '?paymentId=' . urlencode("' OR '1'='1") . '&type=mobile')
            ->assertStatus(400);

        // The real transaction is untouched: still pending, order pending,
        // no completion or failure events, no extra rows.
        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($txn->fresh()->idempotency_key);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->count());
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    // -----------------------------------------------------------------
    // §41.2 — forged webhooks
    // -----------------------------------------------------------------

    /** @test */
    public function stripe_forged_signature_is_400_with_no_state_change(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'cs_sec_1', 'stripe');

        $payload = $this->stripeSessionPayload('evt_sec_bad', 'cs_sec_1');

        // Signed with the wrong secret → HMAC mismatch → 400.
        $this->postStripe($payload, 'whsec_wrong_secret')->assertStatus(400);

        // Missing signature header → 400.
        $this->call('POST', self::STRIPE_URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)
            ->assertStatus(400);

        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($txn->fresh()->idempotency_key);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function paypal_missing_headers_and_bad_signature_are_rejected_untouched(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'PAYPAL-SEC-1', 'paypal');

        $body = $this->paypalCompletedPayload('WH-SEC-1', 'PAYPAL-SEC-1');

        // NOTE: the route controller is constructed on the FIRST request to
        // its route and then reused, so the verifier seam is bound ONCE up
        // front. The missing-headers post below never reaches verification.
        $this->bindPayPalVerifier(['verification_status' => 'FAILURE']);

        // Missing transmission headers → 400, untouched.
        $this->postPayPal($body, false)->assertStatus(400);
        $this->assertSame('pending', $txn->fresh()->status);

        // Provider reports the signature invalid → 401, untouched.
        $badSig = $this->postPayPal($body, true);
        $badSig->assertStatus(401);
        $badSig->assertJsonPath('success', false);
        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($txn->fresh()->idempotency_key);

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function replayed_webhook_event_id_completes_only_once(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'cs_sec_replay', 'stripe');

        // Re-verification runs once (first delivery); the replay hits the
        // event-id dedupe list and never re-verifies.
        $this->mockFactoryVerify('stripe', 'cs_sec_replay', $this->paidResult('cs_sec_replay'), 1);

        $payload = $this->stripeSessionPayload('evt_sec_replay', 'cs_sec_replay');

        $this->postStripe($payload)->assertStatus(200);
        $replay = $this->postStripe($payload);
        $replay->assertStatus(200);
        $replay->assertJsonPath('data.status', 'ignored');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('status', 'paid')->count());

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);
        $this->assertDatabaseCount('payment_reconciliation_results', 0);
    }

    // -----------------------------------------------------------------
    // §41.3 — invalid gateway code at checkout
    // -----------------------------------------------------------------

    /** @test */
    public function invalid_gateway_code_at_checkout_is_422_with_no_transactions(): void
    {
        $customer = $this->createCustomer();
        Sanctum::actingAs($customer);

        $product = Product::create([
            'name' => 'Security Digital',
            'slug' => 'security-digital-' . uniqid(),
            'price' => 100,
            'product_type' => ProductType::SIMPLE,
            'item_type' => ItemType::DIGITAL,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 100,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);

        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => 100]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
            'total_price' => 100,
            'shipping_method' => ShippingMethod::SCHEDULED,
            'is_gift' => false,
        ]);

        $payload = [
            'name' => 'Security Customer',
            'user_phone' => '01000000000',
            'user_email' => $customer->email,
            'address' => ['street' => '123 Security Street'],
            'payment_method' => 'online',
            'fulfillment_type' => 'delivery',
            'type' => 'mobile',
        ];

        foreach (['no-such-gateway', "' OR '1'='1"] as $code) {
            // Fresh cart per attempt: the first attempt consumes the slice.
            CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 100,
                'total_price' => 100,
                'shipping_method' => ShippingMethod::SCHEDULED,
                'is_gift' => false,
            ]);

            $response = $this->postJson('/api/v1/general/checkout', array_merge($payload, ['gateway' => $code]));

            $response->assertStatus(422, "Gateway code [{$code}] must be 422");
            $response->assertJsonPath('success', false);
        }

        // The only side effect is pending orders; zero transaction rows exist.
        $this->assertDatabaseCount('transactions', 0);
        foreach (Order::where('user_id', $customer->id)->get() as $order) {
            $this->assertSame('pending', $order->status);
        }
    }

    // -----------------------------------------------------------------
    // §41.4 — IDOR: cross-user order access
    // -----------------------------------------------------------------

    /** @test */
    public function user_b_cannot_see_or_touch_user_a_order(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $orderA = $this->makeKwdOrder(100.0);
        $txnA = $this->makePendingTxn($orderA, 'PAY-SEC-A');

        // Complete A's order so the invoice path is meaningful (pending
        // orders have no invoice → ambiguous 404).
        $this->mockMyfatoorahTransport($this->paidVerifyPayload('PAY-SEC-A', 100.0));
        $this->get(self::CALLBACK . '?paymentId=PAY-SEC-A&type=mobile')->assertStatus(200);
        $this->assertSame('completed', $orderA->fresh()->status);

        $userB = $this->createCustomer();
        Sanctum::actingAs($userB);

        // Order detail of another user → 404, no order data leaked.
        $show = $this->getJson('/api/v1/general/orders/' . $orderA->id);
        $show->assertStatus(404);
        $show->assertJsonPath('success', false);
        $this->assertStringNotContainsString($orderA->user->email, $show->getContent());

        // Invoice lookup of another user → 404 (ownership inside the query).
        $invoice = $this->getJson('/api/v1/general/orders/' . $orderA->id . '/invoice');
        $invoice->assertStatus(404);
        $this->assertStringNotContainsString($orderA->user->email, $invoice->getContent());

        // Callback with A's paymentId while authed as B: the callback is a
        // PUBLIC provider-redirect endpoint (lookup is by provider ref, not
        // by session), so auth identity is irrelevant. With a FAILED
        // verification B learns no order data and A's ORDER row is untouched
        // (the fail-closed txn marking is the designed mismatch behavior).
        $this->mockMyfatoorahTransport($this->failedVerifyPayload('PAY-SEC-A', 100.0));
        $callback = $this->get(self::CALLBACK . '?paymentId=PAY-SEC-A&type=mobile');
        $callback->assertStatus(200);
        $callback->assertJsonPath('data.status', 'failed');
        $this->assertStringNotContainsString($orderA->user->email, $callback->getContent());
        $this->assertArrayNotHasKey('order', (array) $callback->json('data', []));
        $this->assertSame('completed', $orderA->fresh()->status);
    }

    /** @test */
    public function mark_paid_authorization_boundary_proven(): void
    {
        // A's COD order with a pending COD transaction.
        $orderA = $this->makeKwdOrder(130.0);
        $txnA = $orderA->transactions()->create([
            'user_id' => $orderA->user_id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 130.0,
            'currency' => 'KWD',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        // User B (plain customer, no payments.mark_paid) → 403, untouched.
        $userB = $this->createCustomer();
        Sanctum::actingAs($userB);

        $this->postJson('/api/v1/general/checkout/cod/' . $orderA->id . '/mark-paid')
            ->assertStatus(403);

        $this->assertSame('pending', $orderA->fresh()->status);
        $this->assertSame('pending', $txnA->fresh()->status);

        // PROVEN current behavior: a platform admin WITH payments.mark_paid
        // CAN mark-paid any order (200). This is NOT a defect: the orders
        // table carries no merchant/shop ownership column (Order::$fillable
        // has no shop_id), so there is no ownership predicate to check
        // against — the dedicated permission IS the authorization boundary,
        // exactly like the admin refund endpoint. Adding a shop check would
        // be a redesign (and would 404 every legitimate admin mark-paid,
        // since admins never own customer orders).
        $admin = $this->createUserWithPermissions(['payments.mark_paid'], 'admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/general/checkout/cod/' . $orderA->id . '/mark-paid')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $orderA->fresh()->status);
        $this->assertSame('paid', $txnA->fresh()->status);
    }

    // -----------------------------------------------------------------
    // §41.5 — mass assignment
    // -----------------------------------------------------------------

    /** @test */
    public function checkout_ignores_forged_pricing_and_status_fields(): void
    {
        $customer = $this->createCustomer();
        Sanctum::actingAs($customer);

        $product = Product::create([
            'name' => 'Mass Assignment Digital',
            'slug' => 'mass-assign-digital-' . uniqid(),
            'price' => 100,
            'product_type' => ProductType::SIMPLE,
            'item_type' => ItemType::DIGITAL,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 100,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);

        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => 100]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
            'total_price' => 100,
            'shipping_method' => ShippingMethod::SCHEDULED,
            'is_gift' => false,
        ]);

        // OrderService builds orders from an explicit $dataArray allowlist
        // (name/phone/email/address/notes/governorate + fulfillment/payment
        // keys); pricing/status/currency/user keys can never ride along.
        $transport = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $transport->shouldReceive('createInvoice')->once()->andReturn([
            'Data' => ['InvoiceURL' => 'https://pay.example.test/x', 'InvoiceId' => 'PAY-SEC-MASS'],
        ]);
        $transport->shouldIgnoreMissing();
        $this->app->instance(\App\Services\General\MyfatoraService::class, $transport);

        $response = $this->postJson('/api/v1/general/checkout', [
            'name' => 'Mass Customer',
            'user_phone' => '01000000000',
            'user_email' => $customer->email,
            'address' => ['street' => '123 Mass Street'],
            'payment_method' => 'online',
            'gateway' => 'myfatoorah',
            'fulfillment_type' => 'delivery',
            'type' => 'mobile',
            // Forged server-owned fields — must all be ignored.
            'payment_status' => 'payment-success',
            'status' => 'completed',
            'total_price' => 0.01,
            'currency_code' => 'USD',
            'user_id' => 999999,
        ]);

        $response->assertStatus(200);

        $order = Order::where('user_id', $customer->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertSame('pending', $order->status);
        $this->assertSame('payment-pending', $order->payment_status);
        // Server-computed total wins over the forged 0.01 (exact figure is
        // the pricing engine's business; the invariant is attacker input lost).
        $this->assertNotEquals(0.01, (float) $order->total_price);
        $this->assertSame('KWD', $order->currency_code);
        $this->assertSame($customer->id, (int) $order->user_id);
        $this->assertDatabaseMissing('orders', ['id' => $order->id, 'total_price' => 0.01]);

        $txn = $order->transactions()->latest()->first();
        $this->assertNotNull($txn);
        $this->assertEquals((float) $order->total_price, (float) $txn->amount);
        $this->assertSame('KWD', $txn->currency);
    }

    /** @test */
    public function admin_status_update_ignores_non_status_fields(): void
    {
        // The Marvel PATCH orders/{id}/status path consumes ONLY
        // $request->status (validated against the status enum); every other
        // key is dropped, never mass-assigned.
        $order = $this->makeKwdOrder(100.0);
        $serverTotal = (float) $order->fresh()->total_price;
        $admin = $this->createUserWithPermissions(['update-order-status'], 'admin');
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/orders/' . $order->id . '/status', [
            'status' => 'processing',
            'payment_status' => 'payment-success',
            'total_price' => 0.01,
            'currency_code' => 'USD',
        ])->assertStatus(200);

        $fresh = $order->fresh();
        $this->assertSame('processing', $fresh->status);
        $this->assertEquals($serverTotal, (float) $fresh->total_price);
        $this->assertSame('KWD', $fresh->currency_code);
        $this->assertNotSame('payment-success', $fresh->payment_status);
    }

    // -----------------------------------------------------------------
    // §41.6 — secret leakage across payment surfaces
    // -----------------------------------------------------------------

    /** @test */
    public function payment_responses_contain_no_secrets(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        config([
            'payment.gateways.myfatoorah.api_key' => 'live-api-key-SENTINEL',
            'payment.gateways.stripe.secret_key' => 'sk_live_SENTINEL123',
            'payment.gateways.stripe.webhook_secret' => self::STRIPE_SECRET,
            'payment.gateways.paypal.client_secret' => 'paypal-secret-SENTINEL',
        ]);

        $sentinels = ['live-api-key-SENTINEL', 'sk_live_SENTINEL123', 'paypal-secret-SENTINEL'];
        $forbidden = array_merge($sentinels, ['api_key', 'secret', 'token']);

        $assertClean = function (string $body, string $surface) use ($forbidden): void {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $body, "Surface [{$surface}] leaks [{$needle}]");
            }
        };

        // NOTE: callback and webhook routes use DIFFERENT controllers, each
        // constructed on its first request. The PayPal signature seam is
        // bound up front (harmless to the callback controller); the factory
        // mock is bound after the callback surfaces so the real
        // factory + transport mocks serve the callbacks below.
        $this->bindPayPalVerifier(['verification_status' => 'SUCCESS']);

        // 1. Callback success (myfatoorah, mocked paid).
        $order = $this->makeKwdOrder(100.0);
        $this->makePendingTxn($order, 'PAY-SEC-LEAK');
        $this->mockMyfatoorahTransport($this->paidVerifyPayload('PAY-SEC-LEAK', 100.0));

        $ok = $this->get(self::CALLBACK . '?paymentId=PAY-SEC-LEAK&type=mobile');
        $ok->assertStatus(200);
        $assertClean($ok->getContent(), 'callback-success');

        // 2. Callback failure (myfatoorah, mocked failed).
        $order2 = $this->makeKwdOrder(100.0);
        $this->makePendingTxn($order2, 'PAY-SEC-LEAK2');
        $this->mockMyfatoorahTransport($this->failedVerifyPayload('PAY-SEC-LEAK2', 100.0));

        $fail = $this->get(self::CALLBACK . '?paymentId=PAY-SEC-LEAK2&type=mobile');
        $fail->assertStatus(200);
        $assertClean($fail->getContent(), 'callback-failure');

        // The webhook controller is constructed on the next (stripe) request,
        // so the factory mock is bound here — after the callback surfaces.
        $stripeGateway = \Mockery::mock(PaymentGatewayContract::class);
        $stripeGateway->shouldReceive('verifyPayment')->with('cs_sec_leak')->andReturn($this->paidResult('cs_sec_leak'));
        $paypalGateway = \Mockery::mock(PaymentGatewayContract::class);
        $paypalGateway->shouldReceive('verifyPayment')->with('PAYPAL-SEC-LEAK')->andReturn($this->paidResult('PAYPAL-SEC-LEAK'));

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')->andReturnUsing(
            fn (string $gateway) => $gateway === 'paypal' ? $paypalGateway : $stripeGateway
        );
        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);

        // 3. Stripe webhook rejection (bad signature).
        $rejected = $this->postStripe($this->stripeSessionPayload('evt_leak', 'cs_leak'), 'whsec_wrong');
        $rejected->assertStatus(400);
        $assertClean($rejected->getContent(), 'stripe-webhook-reject');

        // 4. Stripe webhook success (valid sig + mocked verify).
        $order3 = $this->makeKwdOrder(100.0);
        $this->makePendingTxn($order3, 'cs_sec_leak', 'stripe');

        $wh = $this->postStripe($this->stripeSessionPayload('evt_sec_leak', 'cs_sec_leak'));
        $wh->assertStatus(200);
        $assertClean($wh->getContent(), 'stripe-webhook-success');

        // 5. PayPal webhook success (mocked signature + mocked verify).
        $order4 = $this->makeKwdOrder(100.0);
        $this->makePendingTxn($order4, 'PAYPAL-SEC-LEAK', 'paypal');

        $pp = $this->postPayPal($this->paypalCompletedPayload('WH-SEC-LEAK', 'PAYPAL-SEC-LEAK'));
        $pp->assertStatus(200);
        $assertClean($pp->getContent(), 'paypal-webhook-success');

        // 6. Admin gateway settings surfaces.
        $admin = $this->createUserWithPermissions(['view-settings', 'update-settings'], 'admin');
        Sanctum::actingAs($admin);

        $list = $this->getJson('/api/v1/admin/payment-gateways');
        $list->assertStatus(200);
        $assertClean($list->getContent(), 'gateway-settings-list');
    }

    // -----------------------------------------------------------------
    // §41.7 — amount/currency tampering at verify (stripe + paypal)
    // -----------------------------------------------------------------

    /** @test */
    public function stripe_and_paypal_verify_tampering_fails_closed(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $cases = [
            'stripe-amount' => ['stripe', 'cs_sec_t1', 50.0, 'KWD'],
            'stripe-currency' => ['stripe', 'cs_sec_t2', 100.0, 'USD'],
            'paypal-amount' => ['paypal', 'PAYPAL-SEC-T1', 50.0, 'KWD'],
            'paypal-currency' => ['paypal', 'PAYPAL-SEC-T2', 100.0, 'USD'],
        ];

        // NOTE: the callback controller is constructed on the first request
        // and reused, so ONE factory mock serves all four cases (dispatched
        // by provider ref) instead of rebinding between requests.
        $results = [];
        foreach ($cases as [$gateway, $ref, $amount, $currency]) {
            $results[$ref] = new GatewayResult(
                success: true,
                gatewayTransactionId: $ref,
                amount: $amount,
                currency: $currency,
                status: 'paid',
                rawResponse: ['id' => $ref, 'status' => 'paid'],
            );
        }

        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')->andReturnUsing(fn (string $ref) => $results[$ref]);

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')->andReturn($mockGateway);
        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);

        foreach ($cases as $label => [$gateway, $ref, $amount, $currency]) {
            $order = $this->makeKwdOrder(100.0);
            $txn = $this->makePendingTxn($order, $ref, $gateway);

            // Provider-paid but disagreeing with the order on amount/currency.
            $this->get(self::CALLBACK . '?paymentId=' . $ref . '&type=mobile')->assertStatus(400, "Tamper case [{$label}] must be 400");

            $this->assertSame('failed', $txn->fresh()->status, "Tamper case [{$label}] marks txn failed");
            $this->assertSame('pending', $order->fresh()->status, "Tamper case [{$label}] leaves order pending");
        }

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertDispatched(PaymentFailed::class);
    }

    // -----------------------------------------------------------------
    // PayPal verifier fake (file-local so the suite stays standalone)
    // -----------------------------------------------------------------
}

/**
 * In-memory stand-in for the PayPal SDK verify-webhook-signature call.
 */
final class SecFakePayPalVerifyClient
{
    public function __construct(
        private array $response,
    ) {}

    public function verifyWebHook(array $data): array
    {
        return $this->response;
    }
}

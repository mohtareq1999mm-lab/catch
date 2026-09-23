<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\CheckoutTotals;
use App\DTOs\GatewayResult;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Models\CouponReservation;
use App\Models\Currency;
use App\Models\Invoice;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Gateway\PayPalGateway;
use App\Services\Gateway\StripeGateway;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentCurrencyResolver;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\Transaction;
use Marvel\Enums\ProductType;
use Marvel\Enums\ShippingMethod;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * PHASE 12/13 fill-in verification: concurrency (§38-40), financial
 * integrity (§49), lifecycle (§50), and failure injection (§48).
 *
 * No threads/processes anywhere: races are simulated sequentially and the
 * idempotency-token + event-id dedupe + lockForUpdate guards do the
 * serializing, exactly as in production. Mocked gateways, no live calls.
 *
 * NOTE (§38-40.10): double-refund idempotency is NOT duplicated here — it is
 * already proven by GatewayDisableTest::
 * refund_duplicate_idempotency_key_returns_original_without_second_provider_call.
 */
class PaymentConcurrencyTest extends CurrencyTestCase
{
    private const CALLBACK = '/api/v1/general/checkout/callback';

    private const STRIPE_URL = '/api/v1/general/checkout/webhooks/stripe';

    private const STRIPE_SECRET = 'whsec_test_secret_for_concurrency';

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
    // Shared helpers
    // -----------------------------------------------------------------

    private function makeKwdOrder(float $subtotal = 100.0): Order
    {
        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Concurrency Customer',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 Concurrency Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);

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

    /**
     * Physical-stock order with a real reservation, mirroring
     * CartOrderLifecycleTest::createActiveReservation.
     */
    private function makeReservedPhysicalOrder(Product $product, int $qty = 1): Order
    {
        $user = $this->createCustomer();

        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Reservation Test',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'address' => '123 Reservation Street',
            'shipping_method' => 'SCHEDULED',
            'payment_method' => 'online',
            'price' => $product->price * $qty,
            'shipping_price' => 0,
            'total_price' => $product->price * $qty,
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'currency_code' => 'KWD',
            'base_currency_code' => 'KWD',
            'catalog_currency_code' => 'KWD',
        ]);

        $order->orderItems()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'product_quantity' => $qty,
            'product_price' => $product->price,
            'product_total_price' => $product->price * $qty,
        ]);

        app(\App\Services\Inventory\OrderReservationService::class)->reserveForOrder($order->refresh());

        return $order->refresh();
    }

    private function makePhysicalProduct(float $price = 100.0, int $stock = 20): Product
    {
        return Product::create([
            'name' => 'Concurrency Product ' . uniqid(),
            'slug' => 'concurrency-product-' . uniqid(),
            'price' => $price,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => $stock,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);
    }

    private function makeUsableCoupon(string $code, ?int $limiter = 10): Coupon
    {
        return Coupon::create([
            'code' => $code,
            'name' => 'Concurrency Coupon',
            'slug' => 'coupon-' . uniqid(),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'limiter' => $limiter,
            'used' => 0,
        ]);
    }

    private function handlerRequest(object $user): Request
    {
        $request = Request::create('/dummy', 'POST');
        $request->setUserResolver(fn () => $user);

        return $request;
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

        return "t={$timestamp},v1=" . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
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

    private function postStripe(string $payload)
    {
        return $this->call('POST', self::STRIPE_URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $this->stripeHeader($payload, self::STRIPE_SECRET),
        ], $payload);
    }

    // -----------------------------------------------------------------
    // §38-40.8 — dual callbacks, same transaction
    // -----------------------------------------------------------------

    /** @test */
    public function dual_callbacks_complete_exactly_once_with_single_inventory_commit(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $product = $this->makePhysicalProduct(100.0, 20);
        $order = $this->makeReservedPhysicalOrder($product, 1);
        $this->assertSame(1, $product->fresh()->reserved_quantity);

        $txn = $this->makePendingTxn($order, 'PAY-DUAL-1');
        $this->mockMyfatoorahTransport($this->paidVerifyPayload('PAY-DUAL-1', 100.0));

        // Two sequential deliveries of the same callback stand in for two
        // simultaneous ones: the idempotency token stamped under
        // lockForUpdate serializes them, so the second is a replay.
        $this->get(self::CALLBACK . '?paymentId=PAY-DUAL-1&type=mobile')->assertStatus(200);
        $this->get(self::CALLBACK . '?paymentId=PAY-DUAL-1&type=mobile')->assertStatus(200);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('status', 'paid')->count());

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);
        $this->assertDatabaseCount('payment_reconciliation_results', 0);

        // Inventory committed exactly once: 20 - 1 sold, reservation gone.
        $this->assertSame(Order::INVENTORY_STATE_COMMITTED, $order->fresh()->inventory_state);
        $this->assertSame(19, $product->fresh()->stock_quantity);
        $this->assertSame(0, $product->fresh()->reserved_quantity);
        $this->assertSame(1, $product->fresh()->sold_quantity);
    }

    // -----------------------------------------------------------------
    // §38-40.9 — callback + webhook race, same transaction
    // -----------------------------------------------------------------

    /** @test */
    public function callback_and_webhook_race_completes_only_once(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        // NOTE: route controllers are constructed on first use and reused
        // across loop iterations, so ONE factory mock serves both refs
        // (dispatched by provider ref) instead of rebinding per iteration.
        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')->andReturnUsing(
            fn (string $ref) => $this->paidResult($ref)
        );

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')->with('stripe')->andReturn($mockGateway);
        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);

        foreach (['callback-first', 'webhook-first'] as $order) {
            $o = $this->makeKwdOrder(100.0);
            $txn = $this->makePendingTxn($o, 'cs_race_' . $order, 'stripe');

            // Unbounded verify mock: both entries re-verify; the canonical
            // service serializes on the idempotency token, so exactly one
            // wins and the loser is an idempotent replay.
            $callback = fn () => $this->get(self::CALLBACK . '?paymentId=cs_race_' . $order . '&type=mobile');
            $webhook = fn (string $event) => $this->postStripe($this->stripeSessionPayload($event, 'cs_race_' . $order));

            if ($order === 'callback-first') {
                $callback()->assertStatus(200);
                $webhook('evt_race_cb_first')->assertStatus(200)->assertJsonPath('data.status', 'ignored');
            } else {
                $webhook('evt_race_wh_first')->assertStatus(200)->assertJsonPath('data.status', 'completed');
                $callback()->assertStatus(200);
            }

            $this->assertSame('completed', $o->fresh()->status, "Race [{$order}]: order completed");
            $this->assertSame(1, Transaction::where('order_id', $o->id)->where('status', 'paid')->count(), "Race [{$order}]: single paid txn");
        }

        Event::assertDispatchedTimes(PaymentSucceeded::class, 2);
        Event::assertNotDispatched(PaymentFailed::class);
        $this->assertDatabaseCount('payment_reconciliation_results', 0);
    }

    // -----------------------------------------------------------------
    // §38-40.11 — disable-during-initiation race
    // -----------------------------------------------------------------

    /** @test */
    public function disable_between_availability_check_and_initiation_fails_closed(): void
    {
        $order = $this->makeKwdOrder(50.0);
        $request = $this->handlerRequest($order->user);
        $handler = app(\App\Services\Payment\PaymentCheckoutHandler::class);
        $registry = app(\App\Services\Payment\PaymentGatewayRegistry::class);

        // Availability check passes while the gateway is enabled...
        $this->assertTrue($registry->canInitiate('myfatoorah', 'online', 'KWD')['ok']);

        // ...then the gateway is disabled BEFORE initiation (the race).
        $settings = Settings::query()->first() ?? $this->createSettings();
        $options = is_array($settings->options) ? $settings->options : [];
        $gateways = $options['payment_gateways'] ?? [];
        $gateways['myfatoorah'] = array_merge(is_array($gateways['myfatoorah'] ?? null) ? $gateways['myfatoorah'] : [], ['enabled' => false]);
        $options['payment_gateways'] = $gateways;
        $settings->options = $options;
        $settings->save();

        // The handler re-checks canInitiate at initiation time, so the stale
        // "ok" is worthless: failed-closed 422, no transaction, order pending.
        $response = $handler->handleOnlinePayment($request, $order->fresh(), 50.0, 'myfatoorah', 'https://cb.test', 'https://err.test');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse((bool) $response->getData()->success);
        $this->assertSame(0, Transaction::where('order_id', $order->id)->count());
        $this->assertSame('pending', $order->fresh()->status);

        // Control: re-enabled + provider success → the transaction row is
        // ALWAYS created (pending) before any redirect is returned.
        //
        // RESIDUAL WINDOW (documented, accepted): disabling between the
        // handler's own canInitiate check and the provider call cannot be
        // closed without a distributed lock on settings. Worst case there is
        // still safe — a created invoice always yields a pending transaction
        // row carrying the provider ref, so the payment stays verifiable and
        // completable via callback/webhook. Never charge-then-lose.
        $gateways['myfatoorah']['enabled'] = true;
        $options['payment_gateways'] = $gateways;
        $settings->options = $options;
        $settings->save();

        $transport = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $transport->shouldReceive('createInvoice')->once()->andReturn([
            'Data' => ['InvoiceURL' => 'https://pay.example.test/ok', 'InvoiceId' => 'PAY-RACE-OK'],
        ]);
        $transport->shouldIgnoreMissing();
        $this->app->instance(\App\Services\General\MyfatoraService::class, $transport);

        $order2 = $this->makeKwdOrder(50.0);
        $ok = $handler->handleOnlinePayment(
            $this->handlerRequest($order2->user),
            $order2->fresh(),
            50.0,
            'myfatoorah',
            'https://cb.test',
            'https://err.test',
        );

        $this->assertSame(200, $ok->getStatusCode());
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order2->id,
            'payment_method' => 'myfatoorah',
            'status' => 'pending',
            'gateway_transaction_id' => 'PAY-RACE-OK',
        ]);
    }

    // -----------------------------------------------------------------
    // §49.12 — stripe + paypal handler-level financial integrity
    // -----------------------------------------------------------------

    /** @test */
    public function stripe_handler_uses_catalog_currency_despite_usd_preference(): void
    {
        config(['payment.gateways.stripe.enabled' => true]);

        // USD preference vs KWD catalog: the catalog always wins.
        $customer = $this->createCustomerWithCurrencyPreference('USD');
        $this->assertSame('USD', app(CurrencyService::class)->getEffectiveCode());
        $this->assertSame('KWD', app(CurrencyService::class)->getCatalogCode());

        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => 13.25]);
        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Stripe Integrity',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 Integrity Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals(13.25, 0, 0, 13.25),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);
        $order = $order->fresh();
        $this->assertSame('KWD', $order->currency_code);

        $fake = new SecStripeClient(
            new SecStripeCheckout(new SecStripeSessions()),
            new SecStripeIntents(),
            new SecStripeRefunds(),
        );
        $fake->checkoutFake->sessions->createResult = StripeObject::constructFrom([
            'id' => 'cs_integrity_1',
            'url' => 'https://checkout.stripe.test/pay/cs_integrity_1',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'amount_total' => 13250,
            'currency' => 'kwd',
        ]);
        $fake->checkoutFake->sessions->retrieveResults['cs_integrity_1'] = StripeObject::constructFrom([
            'id' => 'cs_integrity_1',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 13250,
            'currency' => 'kwd',
            'payment_intent' => 'pi_integrity_1',
        ]);
        $fake->paymentIntentsFake->retrieveResult = StripeObject::constructFrom([
            'id' => 'pi_integrity_1',
            'amount' => 13250,
            'currency' => 'kwd',
            'status' => 'succeeded',
        ]);

        $this->app->instance(StripeGateway::class, new StripeGateway(app(PaymentCurrencyResolver::class), $fake));

        $response = app(\App\Services\Payment\PaymentCheckoutHandler::class)->handleOnlinePayment(
            $this->handlerRequest($customer),
            $order,
            13.25,
            'stripe',
            'https://shop.test/cb',
            'https://shop.test/er',
        );

        $this->assertSame(200, $response->getStatusCode());

        // Provider saw the catalog currency in minor units (13.25 KWD x 1000).
        $params = $fake->checkoutFake->sessions->lastCreateParams;
        $this->assertSame('kwd', $params['line_items'][0]['price_data']['currency']);
        $this->assertSame(13250, $params['line_items'][0]['price_data']['unit_amount']);

        // Transaction row matches the order exactly.
        $txn = Transaction::where('order_id', $order->id)->latest()->first();
        $this->assertNotNull($txn);
        $this->assertEqualsWithDelta(13.25, (float) $txn->amount, 0.000001);
        $this->assertSame('KWD', $txn->currency);

        // Provider verify agrees: amount == order total == txn amount,
        // currency == order == catalog.
        $verified = app(StripeGateway::class)->verifyPayment('cs_integrity_1');
        $this->assertTrue($verified->success);
        $this->assertEqualsWithDelta((float) $order->total_price, (float) $verified->amount, 0.000001);
        $this->assertEqualsWithDelta((float) $txn->amount, (float) $verified->amount, 0.000001);
        $this->assertSame('KWD', $verified->currency);
        $this->assertSame($order->currency_code, $verified->currency);
    }

    /** @test */
    public function paypal_handler_uses_catalog_currency_despite_usd_preference(): void
    {
        config(['payment.gateways.paypal.enabled' => true]);

        $customer = $this->createCustomerWithCurrencyPreference('USD');
        $this->assertSame('KWD', app(CurrencyService::class)->getCatalogCode());

        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => 13.25]);
        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'PayPal Integrity',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 Integrity Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals(13.25, 0, 0, 13.25),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);
        $order = $order->fresh();
        $this->assertSame('KWD', $order->currency_code);

        $fake = new SecPayPalClient();
        $fake->stubs['createOrder'] = [
            'id' => 'PAYPAL-INTEG-1',
            'status' => 'CREATED',
            'intent' => 'CAPTURE',
            'links' => [
                ['href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-INTEG-1', 'rel' => 'approve', 'method' => 'GET'],
            ],
        ];

        $gateway = new PayPalGateway(app(PaymentCurrencyResolver::class), fn (string $currency = 'USD') => $fake);
        $this->app->instance(PayPalGateway::class, $gateway);

        $response = app(\App\Services\Payment\PaymentCheckoutHandler::class)->handleOnlinePayment(
            $this->handlerRequest($customer),
            $order,
            13.25,
            'paypal',
            'https://shop.test/cb',
            'https://shop.test/er',
        );

        $this->assertSame(200, $response->getStatusCode());

        // Provider payload carries the catalog currency in major units
        // (KWD keeps all three decimals).
        $payload = $fake->calls['createOrder'][0];
        $this->assertSame('KWD', $payload['purchase_units'][0]['amount']['currency_code']);
        $this->assertSame('13.250', $payload['purchase_units'][0]['amount']['value']);
        $this->assertSame('order-' . $order->id . '-1', $fake->headers['PayPal-Request-Id']);

        $txn = Transaction::where('order_id', $order->id)->latest()->first();
        $this->assertNotNull($txn);
        $this->assertEquals(13.25, (float) $txn->amount);
        $this->assertSame('KWD', $txn->currency);

        // Provider verify agrees end to end.
        $unit = [
            'reference_id' => 'order-PAYPAL-INTEG-1',
            'amount' => ['currency_code' => 'KWD', 'value' => '13.25'],
            'payments' => ['captures' => [
                ['id' => 'CAP-PAYPAL-INTEG-1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'KWD', 'value' => '13.25']],
            ]],
        ];
        $fake->stubs['showOrderDetails'] = ['id' => 'PAYPAL-INTEG-1', 'status' => 'COMPLETED', 'purchase_units' => [$unit]];

        $verified = $gateway->verifyPayment('PAYPAL-INTEG-1');
        $this->assertTrue($verified->success);
        $this->assertEquals((float) $order->total_price, (float) $verified->amount);
        $this->assertEquals((float) $txn->amount, (float) $verified->amount);
        $this->assertSame('KWD', $verified->currency);
    }

    // -----------------------------------------------------------------
    // §50.13 — order lifecycle state map (myfatoorah, HTTP checkout)
    // -----------------------------------------------------------------

    /** @test */
    public function order_lifecycle_pending_to_paid_to_completed(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $country = Country::create(['name' => 'Lifecycle Country', 'status' => true]);
        $governorate = Governorate::create(['country_id' => $country->id, 'name' => 'Lifecycle Gov', 'status' => true]);
        ShippingPrice::create(['governorate_id' => $governorate->id, 'price' => 0, 'status' => true]);

        $customer = $this->createCustomer();
        Sanctum::actingAs($customer);

        $product = $this->makePhysicalProduct(100.0, 20);

        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => 0]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
            'total_price' => 100,
            'shipping_method' => ShippingMethod::SCHEDULED,
            'is_gift' => false,
        ]);

        $coupon = $this->makeUsableCoupon('LIFECYCLE-' . strtoupper(Str::random(6)));
        $cart->update(['coupon' => $coupon->code]);

        $create = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $create->shouldReceive('createInvoice')->once()->andReturn([
            'Data' => ['InvoiceURL' => 'https://pay.example.test/lc', 'InvoiceId' => 'PAY-LC-1'],
        ]);
        $create->shouldIgnoreMissing();
        $this->app->instance(\App\Services\General\MyfatoraService::class, $create);

        // checkout → pending.
        $checkout = $this->postJson('/api/v1/general/checkout', [
            'name' => 'Lifecycle Customer',
            'user_phone' => '01000000000',
            'user_email' => $customer->email,
            'address' => ['street' => '123 Lifecycle Street'],
            'governorate_id' => $governorate->id,
            'payment_method' => 'online',
            'gateway' => 'myfatoorah',
            'fulfillment_type' => 'delivery',
            'type' => 'mobile',
        ]);

        $checkout->assertStatus(200)->assertJsonPath('success', true);

        $order = Order::where('user_id', $customer->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertSame('pending', $order->status);
        $this->assertSame(Order::PAYMENT_STATUS_PENDING, $order->payment_status);

        $txn = $order->transactions()->latest()->first();
        $this->assertNotNull($txn);
        $this->assertSame('pending', $txn->status);
        $this->assertEquals((float) $order->total_price, (float) $txn->amount);
        $this->assertSame('KWD', $txn->currency);

        // Inventory reserved, coupon reserved, no invoice, no events yet.
        $this->assertSame(Order::INVENTORY_STATE_ACTIVE, $order->inventory_state);
        $this->assertSame(1, $product->fresh()->reserved_quantity);
        $this->assertSame(1, CouponReservation::where('order_id', $order->id)->count());
        $this->assertSame(0, Invoice::where('order_id', $order->id)->count());
        Event::assertNotDispatched(PaymentSucceeded::class);

        // paid → completed via the canonical callback.
        $total = (float) $order->fresh()->total_price;
        $this->mockMyfatoorahTransport($this->paidVerifyPayload('PAY-LC-1', $total));

        $this->get(self::CALLBACK . '?paymentId=PAY-LC-1&type=mobile')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'success');

        $order = $order->fresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $order->payment_status);
        $this->assertSame(Order::FULFILLMENT_STATUS_PROCESSING, $order->fulfillment_status);
        $this->assertNotNull($order->paid_at);

        $txn = $txn->fresh();
        $this->assertSame('paid', $txn->status);
        $this->assertNotNull($txn->paid_at);
        $this->assertNotNull($txn->idempotency_key);

        // Inventory committed once, coupon consumed, invoice issued, one event.
        $this->assertSame(Order::INVENTORY_STATE_COMMITTED, $order->inventory_state);
        $this->assertSame(19, $product->fresh()->stock_quantity);
        $this->assertSame(0, $product->fresh()->reserved_quantity);
        $this->assertSame(1, $product->fresh()->sold_quantity);

        $this->assertSame(1, $coupon->fresh()->used);
        $this->assertTrue((bool) $order->coupon_consumed);
        $this->assertSame(0, CouponReservation::where('order_id', $order->id)->count());

        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    // -----------------------------------------------------------------
    // §48.14 — provider timeout/exception at createInvoice
    // -----------------------------------------------------------------

    /** @test */
    public function provider_exception_at_create_invoice_releases_coupon_and_stays_retryable(): void
    {
        $coupon = $this->makeUsableCoupon('RETRY-' . strtoupper(Str::random(6)), 5);

        $order = $this->makeKwdOrder(100.0);
        $order->update(['coupon' => $coupon->code]);
        $order = $order->fresh();

        $handler = app(\App\Services\Payment\PaymentCheckoutHandler::class);
        $request = $this->handlerRequest($order->user);

        // Provider timeout: transport throws instead of returning.
        $throwing = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $throwing->shouldReceive('createInvoice')->once()->andThrow(new \RuntimeException('connection timed out'));
        $this->app->instance(\App\Services\General\MyfatoraService::class, $throwing);

        $failed = $handler->handleOnlinePayment($request, $order, 100.0, 'myfatoorah', 'https://cb.test', 'https://err.test');

        $this->assertSame(500, $failed->getStatusCode());
        $this->assertFalse((bool) $failed->getData()->success);
        $this->assertSame(0, Transaction::where('order_id', $order->id)->count());
        $this->assertSame('pending', $order->fresh()->status);
        // REGRESSION (§48.14): the reservation taken before the provider call
        // is released — before the fix it lingered until TTL.
        $this->assertSame(0, CouponReservation::where('order_id', $order->id)->count());

        // Retry after the provider recovers: re-reserves and creates the
        // pending transaction (never wedged on a stale reservation).
        $working = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $working->shouldReceive('createInvoice')->once()->andReturn([
            'Data' => ['InvoiceURL' => 'https://pay.example.test/retry', 'InvoiceId' => 'PAY-RETRY-1'],
        ]);
        $working->shouldIgnoreMissing();
        $this->app->instance(\App\Services\General\MyfatoraService::class, $working);

        $retry = $handler->handleOnlinePayment($request, $order->fresh(), 100.0, 'myfatoorah', 'https://cb.test', 'https://err.test');

        $this->assertSame(200, $retry->getStatusCode());
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'myfatoorah',
            'status' => 'pending',
            'gateway_transaction_id' => 'PAY-RETRY-1',
        ]);
    }

    /** @test */
    public function malformed_provider_create_response_fails_closed_without_transaction(): void
    {
        $coupon = $this->makeUsableCoupon('MALFORM-' . strtoupper(Str::random(6)), 5);

        $order = $this->makeKwdOrder(100.0);
        $order->update(['coupon' => $coupon->code]);

        // Non-array provider response: the adapter fails closed, the handler
        // releases the coupon reservation and creates no transaction.
        $transport = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $transport->shouldReceive('createInvoice')->once()->andReturn('not-an-array');
        $transport->shouldIgnoreMissing();
        $this->app->instance(\App\Services\General\MyfatoraService::class, $transport);

        $response = app(\App\Services\Payment\PaymentCheckoutHandler::class)->handleOnlinePayment(
            $this->handlerRequest($order->user),
            $order->fresh(),
            100.0,
            'myfatoorah',
            'https://cb.test',
            'https://err.test',
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(0, Transaction::where('order_id', $order->id)->count());
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, CouponReservation::where('order_id', $order->id)->count());
    }

    // -----------------------------------------------------------------
    // §48.15 — malformed (non-array) provider responses, all gateways
    // -----------------------------------------------------------------

    /** @test */
    public function malformed_provider_responses_fail_closed_for_all_gateways(): void
    {
        // MyFatoorah: transport returns non-arrays.
        $mfTransport = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $mfTransport->shouldReceive('createInvoice')->andReturn(42);
        $mfTransport->shouldReceive('checkInvoice')->andReturn('garbage');
        $mfTransport->shouldReceive('makeRefund')->andReturn(null);
        $this->app->instance(\App\Services\General\MyfatoraService::class, $mfTransport);

        $mfOrder = $this->makeKwdOrder(100.0);
        $mfGateway = app(\App\Services\Gateway\MyFatoorahGateway::class);

        $this->assertFalse($mfGateway->createInvoice($mfOrder, 100.0, 'https://cb.test', 'https://err.test')->success);
        $this->assertFalse($mfGateway->verifyPayment('PAY-MAL-1')->success);

        // Stripe: SDK layer returns non-objects.
        $stripeFake = new SecStripeClient(
            new SecStripeCheckout(new SecStripeSessions()),
            new SecStripeIntents(),
            new SecStripeRefunds(),
        );
        $stripeFake->checkoutFake->sessions->createResult = 'garbage-string';
        $stripeFake->checkoutFake->sessions->retrieveResults['cs_mal_1'] = null;

        $stripeOrder = $this->makeKwdOrder(100.0);
        $stripeGateway = new StripeGateway(app(PaymentCurrencyResolver::class), $stripeFake);

        $stripeCreate = $stripeGateway->createInvoice($stripeOrder, 100.0, 'https://cb.test', 'https://err.test');
        $this->assertFalse($stripeCreate->success);
        $this->assertSame('Invalid gateway response', $stripeCreate->errorMessage);

        $this->assertFalse($stripeGateway->verifyPayment('cs_mal_1')->success);

        // Stripe refund with a malformed refund object fails closed too.
        $stripeFake->checkoutFake->sessions->retrieveResults['cs_mal_r'] = StripeObject::constructFrom([
            'id' => 'cs_mal_r',
            'payment_status' => 'paid',
            'amount_total' => 10000,
            'currency' => 'kwd',
            'payment_intent' => 'pi_mal_r',
        ]);
        $stripeFake->refundsFake->createResult = null;
        $stripeOrder->transactions()->create([
            'user_id' => $stripeOrder->user_id,
            'payment_method' => 'stripe',
            'status' => 'paid',
            'amount' => 100.0,
            'currency' => 'KWD',
            'gateway_transaction_id' => 'cs_mal_r',
            'invoice_id' => 'cs_mal_r',
        ]);
        $this->assertFalse($stripeGateway->refund($stripeOrder->fresh(), 100.0)->success);

        // PayPal: SDK layer returns non-arrays.
        $ppFake = new SecPayPalClient();
        $ppFake->stubs['createOrder'] = 'garbage-string';
        $ppFake->stubs['showOrderDetails'] = 42;
        $ppFake->stubs['refundCapturedPayment'] = 'garbage-string';

        $ppOrder = $this->makeKwdOrder(100.0);
        $ppGateway = new PayPalGateway(app(PaymentCurrencyResolver::class), fn (string $currency = 'USD') => $ppFake);

        $this->assertFalse($ppGateway->createInvoice($ppOrder, 100.0, 'https://cb.test', 'https://err.test')->success);
        $this->assertFalse($ppGateway->verifyPayment('PAYPAL-MAL-1')->success);

        $ppOrder->transactions()->create([
            'user_id' => $ppOrder->user_id,
            'payment_method' => 'paypal',
            'status' => 'paid',
            'amount' => 100.0,
            'currency' => 'KWD',
            'gateway_transaction_id' => 'PAYPAL-MAL-2',
            'invoice_id' => 'PAYPAL-MAL-2',
        ]);
        $this->assertFalse($ppGateway->refund($ppOrder->fresh(), 100.0)->success);
    }
}

// ---------------------------------------------------------------------
// File-local fakes (Sec* prefix avoids collisions with StripeGatewayTest /
// PayPalGatewayTest helpers when the whole suite runs in one process).
// ---------------------------------------------------------------------

class SecStripeSessions
{
    public ?array $lastCreateParams = null;

    public int $createCalls = 0;

    public mixed $createResult = null;

    /** @var array<string, mixed> */
    public array $retrieveResults = [];

    public ?string $lastRetrieveId = null;

    public function create($params = null, $opts = null)
    {
        $this->createCalls++;
        $this->lastCreateParams = is_array($params) ? $params : [];

        return $this->createResult;
    }

    public function retrieve($id, $params = null, $opts = null)
    {
        $this->lastRetrieveId = $id;

        return $this->retrieveResults[$id] ?? null;
    }
}

class SecStripeCheckout
{
    public function __construct(public SecStripeSessions $sessions) {}
}

class SecStripeIntents
{
    public mixed $retrieveResult = null;

    public ?string $lastRetrieveId = null;

    public function retrieve($id, $params = null, $opts = null)
    {
        $this->lastRetrieveId = $id;

        return $this->retrieveResult;
    }
}

class SecStripeRefunds
{
    public ?array $lastCreateParams = null;

    public int $createCalls = 0;

    public mixed $createResult = null;

    public function create($params = null, $opts = null)
    {
        $this->createCalls++;
        $this->lastCreateParams = is_array($params) ? $params : [];

        return $this->createResult;
    }
}

class SecStripeClient extends StripeClient
{
    public SecStripeCheckout $checkoutFake;

    public SecStripeIntents $paymentIntentsFake;

    public SecStripeRefunds $refundsFake;

    public function __construct(SecStripeCheckout $checkout, SecStripeIntents $paymentIntents, SecStripeRefunds $refunds)
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

final class SecPayPalClient
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
        $this->calls['createOrder'][] = $data;

        return $this->respond('createOrder');
    }

    public function showOrderDetails(string $orderId): mixed
    {
        $this->calls['showOrderDetails'][] = $orderId;

        return $this->respond('showOrderDetails');
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

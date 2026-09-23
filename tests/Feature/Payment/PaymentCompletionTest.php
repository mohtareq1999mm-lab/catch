<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\CheckoutTotals;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Gateway\MyFatoorahGateway;
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
 * Canonical payment completion (PaymentCompletionService), D-05 zero-value
 * policy, MyFatoorah refund validation, and gateway_response allowlisting.
 */
class PaymentCompletionTest extends CurrencyTestCase
{
    private const CALLBACK = '/api/v1/general/checkout/callback';

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
        $this->seedCurrencyData();

        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());

        return $this->makeKwdOrderSeeded($subtotal);
    }

    /**
     * Same as makeKwdOrder() but assumes seedCurrencyData() + KWD catalog
     * were already established (for tests creating several orders).
     */
    private function makeKwdOrderSeeded(float $subtotal = 100.0): Order
    {
        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Completion Customer',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 Completion Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);
        $this->assertSame('KWD', $order->fresh()->currency_code);

        return $order->fresh();
    }

    private function makePendingTxn(Order $order, string $payId, array $overrides = []): Transaction
    {
        return $order->transactions()->create(array_merge([
            'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah',
            'status' => 'pending',
            'amount' => (float) $order->total_price,
            'currency' => 'KWD',
            'gateway_transaction_id' => $payId,
            'invoice_id' => $payId,
        ], $overrides));
    }

    /**
     * Mock the provider transport (MyfatoraService) so the REAL gateway
     * adapter code — verify mapping, allowlist, refund validation — runs.
     *
     * @param array|callable $checkInvoice payload or fn(array $data): ?array
     */
    private function mockProviderTransport($checkInvoice = null): \Mockery\MockInterface
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

        return $mock;
    }

    private function paidVerifyPayload(string $payId, float $amount, array $extra = []): array
    {
        return [
            'IsSuccess' => true,
            'Message' => 'Paid',
            'Data' => array_merge([
                'InvoiceId' => $payId,
                'InvoiceStatus' => 'Paid',
                'InvoiceValue' => $amount,
                'DisplayCurrencyIso' => 'KWD',
                'InvoiceURL' => 'https://pay.example.test/' . $payId,
                // PII the provider returns but we must never persist:
                'CustomerName' => 'Leaky Customer',
                'CustomerEmail' => 'leak@example.com',
                'CustomerMobile' => '01099999999',
            ], $extra),
        ];
    }

    // -----------------------------------------------------------------
    // 1. Canonical completion via callback
    // -----------------------------------------------------------------

    /** @test */
    public function callback_success_completes_order_once_with_sanitized_response(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'PAY-1001', [
            'gateway_response' => ['_callback_type' => 'mobile'],
        ]);

        $this->mockProviderTransport($this->paidVerifyPayload('PAY-1001', 100.0));

        $response = $this->get(self::CALLBACK . '?paymentId=PAY-1001&type=mobile');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'success');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('payment-success', $order->fresh()->payment_status);

        $freshTxn = $txn->fresh();
        $this->assertSame('paid', $freshTxn->status);
        $this->assertNotNull($freshTxn->paid_at);
        $this->assertNotNull($freshTxn->idempotency_key);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);

        // Allowlist: technical fields kept, customer PII stripped.
        $stored = $freshTxn->gateway_response['Data'] ?? [];
        $this->assertSame('PAY-1001', $stored['InvoiceId'] ?? null);
        $this->assertSame('Paid', $stored['InvoiceStatus'] ?? null);
        $this->assertArrayNotHasKey('CustomerName', $stored);
        $this->assertArrayNotHasKey('CustomerEmail', $stored);
        $this->assertArrayNotHasKey('CustomerMobile', $stored);
        $this->assertStringNotContainsString('leak@example.com', json_encode($freshTxn->gateway_response));
        $this->assertStringNotContainsString('01099999999', json_encode($freshTxn->gateway_response));
        // Controller/handler-layer keys still merge afterwards.
        $this->assertSame('mobile', $freshTxn->gateway_response['_callback_type'] ?? null);
    }

    /** @test */
    public function callback_replay_is_idempotent_single_completion(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'PAY-1002');

        $this->mockProviderTransport($this->paidVerifyPayload('PAY-1002', 100.0));

        $this->get(self::CALLBACK . '?paymentId=PAY-1002&type=mobile')->assertStatus(200);
        $this->get(self::CALLBACK . '?paymentId=PAY-1002&type=mobile')->assertStatus(200);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);

        // Exactly one canonical completion: one PaymentSucceeded, no failure.
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);
        $this->assertDatabaseCount('payment_reconciliation_results', 0);
    }

    /** @test */
    public function callback_amount_and_currency_mismatch_mark_failed(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $this->seedCurrencyData();
        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());

        // Amount mismatch.
        $order = $this->makeKwdOrderSeeded(100.0);
        $txn = $this->makePendingTxn($order, 'PAY-1003');
        $this->mockProviderTransport($this->paidVerifyPayload('PAY-1003', 50.0));

        $this->get(self::CALLBACK . '?paymentId=PAY-1003&type=mobile')->assertStatus(400);

        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        Event::assertDispatched(PaymentFailed::class);
        Event::assertNotDispatched(PaymentSucceeded::class);

        // Currency mismatch (fresh order/txn, same transport mock replaced).
        $order2 = $this->makeKwdOrderSeeded(100.0);
        $txn2 = $this->makePendingTxn($order2, 'PAY-1004');
        $this->mockProviderTransport($this->paidVerifyPayload('PAY-1004', 100.0, ['DisplayCurrencyIso' => 'USD']));

        $this->get(self::CALLBACK . '?paymentId=PAY-1004&type=mobile')->assertStatus(400);

        $this->assertSame('failed', $txn2->fresh()->status);
        $this->assertSame('pending', $order2->fresh()->status);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function callback_provider_ref_mismatch_marks_failed(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'PAY-1005');

        // Provider confirms somebody ELSE's invoice; amount/currency match us.
        $this->mockProviderTransport($this->paidVerifyPayload('PAY-9999', 100.0));

        $this->get(self::CALLBACK . '?paymentId=PAY-1005&type=mobile')->assertStatus(400);

        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function callback_coupon_blocked_fails_and_stays_retryable(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        // Ghost coupon: consumption refuses at completion (fail-closed).
        $order->update(['coupon' => 'GHOST-CODE-XYZ']);
        $txn = $this->makePendingTxn($order, 'PAY-1006');

        $this->mockProviderTransport($this->paidVerifyPayload('PAY-1006', 100.0));

        $this->get(self::CALLBACK . '?paymentId=PAY-1006&type=mobile')->assertStatus(400);

        $freshTxn = $txn->fresh();
        $this->assertSame('failed', $freshTxn->status);
        // M2: token rotated so a legitimate retry after ops intervention
        // reprocesses instead of wedging on a stale token.
        $this->assertNull($freshTxn->idempotency_key);
        $this->assertArrayHasKey('_coupon_blocked_reason', $freshTxn->gateway_response ?? []);
        $this->assertSame('pending', $order->fresh()->status);

        Event::assertDispatched(PaymentFailed::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function duplicate_paid_callback_on_second_txn_holds_for_reconciliation(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $txnA = $this->makePendingTxn($order, 'PAY-2001');

        $payloadA = $this->paidVerifyPayload('PAY-2001', 100.0);
        $payloadB = $this->paidVerifyPayload('PAY-2002', 100.0);

        $this->mockProviderTransport(function (array $data) use ($payloadA, $payloadB) {
            return ($data['Key'] ?? null) === 'PAY-2002' ? $payloadB : $payloadA;
        });

        // First completion wins.
        $this->get(self::CALLBACK . '?paymentId=PAY-2001&type=mobile')->assertStatus(200);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txnA->fresh()->status);

        // A SECOND provider-paid invoice for the same (now completed) order.
        $txnB = $this->makePendingTxn($order, 'PAY-2002');

        $this->get(self::CALLBACK . '?paymentId=PAY-2002&type=mobile')->assertStatus(200);

        // D-06: recorded, never a second completion.
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('pending', $txnB->fresh()->status);
        $this->assertDatabaseHas('payment_reconciliation_results', [
            'order_id' => $order->id,
            'transaction_id' => $txnB->id,
            'mismatch_type' => 'duplicate_payment',
        ]);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    /** @test */
    public function success_callback_web_redirects_to_success_url(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'PAY-1007');

        $this->mockProviderTransport($this->paidVerifyPayload('PAY-1007', 100.0));

        // Web callback (no mobile type) redirects on success, like the
        // legacy path — response-identical behavior.
        $response = $this->get(self::CALLBACK . '?paymentId=PAY-1007');

        $response->assertStatus(302);
        $this->assertStringContainsString('payment/success', $response->headers->get('Location'));
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    /** @test */
    public function error_callback_success_completes_order(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'PAY-1008');

        $this->mockProviderTransport($this->paidVerifyPayload('PAY-1008', 100.0));

        // A paid verification arriving on the error callback completes the
        // order atomically (no orphan pending) via the canonical service.
        $response = $this->get('/api/v1/general/checkout/error-callback?paymentId=PAY-1008&type=mobile');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function callback_for_cancelled_order_does_not_resurrect(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'PAY-1009');
        $order->update(['status' => 'cancelled']);

        $this->mockProviderTransport($this->paidVerifyPayload('PAY-1009', 100.0));

        $response = $this->get(self::CALLBACK . '?paymentId=PAY-1009&type=mobile');

        // Legacy fallthrough: success response, order untouched, no events,
        // and — same row, no completing twin — no duplicate-hold row either.
        $response->assertStatus(200);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertDatabaseCount('payment_reconciliation_results', 0);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    // -----------------------------------------------------------------
    // 2. D-05 zero-value policy
    // -----------------------------------------------------------------

    /** @test */
    public function zero_value_online_checkout_completes_without_gateway_call(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $this->seedCurrencyData();
        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());

        $customer = $this->createCustomer();
        Sanctum::actingAs($customer);

        $product = Product::create([
            'name' => 'Free Digital Gift',
            'slug' => 'free-digital-gift-' . uniqid(),
            'price' => 0,
            'product_type' => ProductType::SIMPLE,
            'item_type' => ItemType::DIGITAL,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 100,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);

        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => 0]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 0,
            'total_price' => 0,
            'shipping_method' => ShippingMethod::SCHEDULED,
            'is_gift' => false,
        ]);

        // The gateway must never be touched for a zero-value order.
        $transport = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $transport->shouldNotReceive('createInvoice');
        $transport->shouldNotReceive('checkInvoice');
        $transport->shouldNotReceive('makeRefund');
        $this->app->instance(\App\Services\General\MyfatoraService::class, $transport);

        $response = $this->postJson('/api/v1/general/checkout', [
            'name' => 'Zero Customer',
            'user_phone' => '01000000000',
            'user_email' => $customer->email,
            'address' => ['street' => '123 Zero Street'],
            'payment_method' => 'online',
            'gateway' => 'myfatoorah',
            'fulfillment_type' => 'delivery',
            'type' => 'mobile',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $orderId = $response->json('data.order_id');
        $this->assertNotNull($orderId);

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame('completed', $order->status);
        $this->assertSame('payment-success', $order->payment_status);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'online',
            'status' => 'paid',
            'amount' => 0,
        ]);

        Event::assertDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function zero_value_online_checkout_422_for_gateway_unsupported_currency(): void
    {
        // SHOULD-FIX (c): the gateway is never called for zero-value orders
        // (bypass exemption), but the order currency must still be one the
        // gateway claims to support — otherwise a zero-value order could
        // complete in a currency no provider would settle.
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $this->seedCurrencyData();
        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());
        config(['payment.gateways.myfatoorah.supported_currencies' => ['USD']]);

        $customer = $this->createCustomer();
        Sanctum::actingAs($customer);

        $product = Product::create([
            'name' => 'Free Digital Gift 2',
            'slug' => 'free-digital-gift-2-' . uniqid(),
            'price' => 0,
            'product_type' => ProductType::SIMPLE,
            'item_type' => ItemType::DIGITAL,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 100,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);

        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => 0]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 0,
            'total_price' => 0,
            'shipping_method' => ShippingMethod::SCHEDULED,
            'is_gift' => false,
        ]);

        $response = $this->postJson('/api/v1/general/checkout', [
            'name' => 'Zero Customer',
            'user_phone' => '01000000000',
            'user_email' => $customer->email,
            'address' => ['street' => '123 Zero Street'],
            'payment_method' => 'online',
            'gateway' => 'myfatoorah',
            'fulfillment_type' => 'delivery',
            'type' => 'mobile',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);

        Event::assertNotDispatched(PaymentSucceeded::class);
        $this->assertDatabaseMissing('transactions', [
            'user_id' => $customer->id,
            'status' => 'paid',
        ]);
    }

    // -----------------------------------------------------------------
    // 3. Refund validation matrix
    // -----------------------------------------------------------------

    private function makeRefundableOrder(): Order
    {
        $order = $this->makeKwdOrder(100.0);
        $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah',
            'status' => 'paid',
            'amount' => (float) $order->total_price,
            'currency' => 'KWD',
            'gateway_transaction_id' => 'PAY-3001',
            'invoice_id' => 'PAY-3001',
            'paid_at' => now(),
        ]);

        return $order->fresh();
    }

    /** @test */
    public function refund_success_requires_positive_provider_confirmation(): void
    {
        $order = $this->makeRefundableOrder();

        // Confirmed: status names a completed refund.
        $this->mockProviderTransport();
        $transport = $this->app->make(\App\Services\General\MyfatoraService::class);
        $transport->shouldReceive('makeRefund')->once()->andReturn([
            'IsSuccess' => true,
            'Data' => [
                'RefundId' => 77,
                'RefundStatus' => 'Refunded',
                'CustomerEmail' => 'leak@example.com',
            ],
        ]);

        $result = app(MyFatoorahGateway::class)->refund($order, 100.0);

        $this->assertTrue($result->success);
        $this->assertSame('77', $result->gatewayTransactionId);
        $this->assertStringNotContainsString('leak@example.com', json_encode($result->rawResponse));
    }

    /** @test */
    public function refund_refused_status_is_failure(): void
    {
        $order = $this->makeRefundableOrder();

        $this->mockProviderTransport();
        $transport = $this->app->make(\App\Services\General\MyfatoraService::class);
        $transport->shouldReceive('makeRefund')->once()->andReturn([
            'IsSuccess' => true,
            'Message' => 'Refund rejected by acquirer',
            'Data' => ['RefundId' => null, 'RefundStatus' => 'RefundFailed'],
        ]);

        $result = app(MyFatoorahGateway::class)->refund($order, 100.0);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errorMessage);
    }

    /** @test */
    public function refund_unknown_or_absent_status_is_failure_fail_closed(): void
    {
        $order = $this->makeRefundableOrder();

        // Absent status.
        $this->mockProviderTransport();
        $transport = $this->app->make(\App\Services\General\MyfatoraService::class);
        $transport->shouldReceive('makeRefund')->once()->andReturn([
            'IsSuccess' => true,
            'Message' => 'Under review',
            'Data' => ['RefundId' => 78],
        ]);

        $result = app(MyFatoorahGateway::class)->refund($order, 100.0);
        $this->assertFalse($result->success);

        // Unknown shape.
        $this->mockProviderTransport();
        $transport2 = $this->app->make(\App\Services\General\MyfatoraService::class);
        $transport2->shouldReceive('makeRefund')->once()->andReturn([
            'IsSuccess' => true,
            'Data' => ['SomethingElse' => '???'],
        ]);

        $result2 = app(MyFatoorahGateway::class)->refund($order, 100.0);
        $this->assertFalse($result2->success);

        // Transport failure.
        $this->mockProviderTransport();
        $transport3 = $this->app->make(\App\Services\General\MyfatoraService::class);
        $transport3->shouldReceive('makeRefund')->once()->andReturnNull();

        $result3 = app(MyFatoorahGateway::class)->refund($order, 100.0);
        $this->assertFalse($result3->success);
        $this->assertSame('No response from payment gateway', $result3->errorMessage);
    }
}

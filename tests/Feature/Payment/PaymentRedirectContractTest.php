<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\CheckoutTotals;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use Illuminate\Support\Facades\Event;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * Frontend redirect contract: web callbacks expose order_id only.
 *
 * The real gateway payment ID stays internal (transaction lookup,
 * verification, reconciliation, refunds) and must never appear in the
 * frontend redirect query string.
 */
class PaymentRedirectContractTest extends CurrencyTestCase
{
    private const CALLBACK = '/api/v1/general/checkout/callback';
    private const ERROR_CALLBACK = '/api/v1/general/checkout/error-callback';

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers (mirrors PaymentCompletionTest canonical setup)
    // -----------------------------------------------------------------

    private function makeKwdOrder(float $subtotal = 100.0): Order
    {
        $this->seedCurrencyData();

        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());

        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Redirect Customer',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 Redirect Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        return $order->fresh();
    }

    private function makePendingTxn(Order $order, string $payId): Transaction
    {
        return $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah',
            'status' => 'pending',
            'amount' => (float) $order->total_price,
            'currency' => 'KWD',
            'gateway_transaction_id' => $payId,
            'invoice_id' => $payId,
        ]);
    }

    private function mockProviderTransport($checkInvoice = null): void
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

    /**
     * Exact redirect-query assertions (substring checks would let
     * order_id=1 match order_id=10).
     *
     * @return array<string,string>
     */
    private function redirectQuery(\Illuminate\Testing\TestResponse $response): array
    {
        $location = (string) $response->headers->get('Location');
        $query = parse_url($location, PHP_URL_QUERY) ?: '';
        parse_str($query, $params);

        return array_map('strval', $params);
    }

    // -----------------------------------------------------------------
    // Contract tests
    // -----------------------------------------------------------------

    /** @test */
    public function success_callback_web_redirect_exposes_order_id_only(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'PAY-RC-1');

        $this->mockProviderTransport($this->paidVerifyPayload('PAY-RC-1', 100.0));

        $response = $this->get(self::CALLBACK . '?paymentId=PAY-RC-1');

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('payment/success', $location);
        $this->assertStringNotContainsString('payment_id', $location);
        $this->assertStringNotContainsString('PAY-RC-1', $location);
        $query = $this->redirectQuery($response);
        $this->assertArrayNotHasKey('payment_id', $query);
        $this->assertSame((string) $order->id, $query['order_id'] ?? null);

        // Regression: canonical completion still ran on the gateway ID.
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
        $this->assertSame('PAY-RC-1', $txn->fresh()->gateway_transaction_id);
        $this->assertSame('PAY-RC-1', $txn->fresh()->invoice_id);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    /** @test */
    public function failed_callback_web_redirect_exposes_order_id_only(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'PAY-RC-2');

        $this->mockProviderTransport($this->failedVerifyPayload('PAY-RC-2', 100.0));

        $response = $this->get(self::CALLBACK . '?paymentId=PAY-RC-2');

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('payment/failed', $location);
        $this->assertStringNotContainsString('payment_id', $location);
        $this->assertStringNotContainsString('PAY-RC-2', $location);
        $query = $this->redirectQuery($response);
        $this->assertArrayNotHasKey('payment_id', $query);
        $this->assertSame((string) $order->id, $query['order_id'] ?? null);

        // Regression: failure still marked, order stays pending.
        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        Event::assertDispatched(PaymentFailed::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function error_callback_success_web_redirect_exposes_order_id_only(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'PAY-RC-3');

        $this->mockProviderTransport($this->paidVerifyPayload('PAY-RC-3', 100.0));

        $response = $this->get(self::ERROR_CALLBACK . '?paymentId=PAY-RC-3');

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('payment/success', $location);
        $this->assertStringNotContainsString('payment_id', $location);
        $this->assertStringNotContainsString('PAY-RC-3', $location);
        $query = $this->redirectQuery($response);
        $this->assertArrayNotHasKey('payment_id', $query);
        $this->assertSame((string) $order->id, $query['order_id'] ?? null);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    /** @test */
    public function error_callback_failure_web_redirect_exposes_order_id_only(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'PAY-RC-4');

        $this->mockProviderTransport($this->failedVerifyPayload('PAY-RC-4', 100.0));

        $response = $this->get(self::ERROR_CALLBACK . '?paymentId=PAY-RC-4');

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('payment/failed', $location);
        $this->assertStringNotContainsString('payment_id', $location);
        $this->assertStringNotContainsString('PAY-RC-4', $location);
        $query = $this->redirectQuery($response);
        $this->assertArrayNotHasKey('payment_id', $query);
        $this->assertSame((string) $order->id, $query['order_id'] ?? null);

        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
    }

    /** @test */
    public function unknown_order_web_redirect_has_no_ids_and_stays_failed(): void
    {
        $this->mockProviderTransport($this->paidVerifyPayload('PAY-GHOST-1', 100.0));

        $response = $this->get(self::CALLBACK . '?paymentId=PAY-GHOST-1');

        // Fail-safe: failed page, no invented order ID, no gateway ID leak.
        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('payment/failed', $location);
        $this->assertStringNotContainsString('/payment/success', $location);
        $this->assertStringNotContainsString('payment_id', $location);
        $this->assertStringNotContainsString('order_id', $location);
        $this->assertStringNotContainsString('PAY-GHOST-1', $location);
        $query = $this->redirectQuery($response);
        $this->assertArrayNotHasKey('payment_id', $query);
        $this->assertArrayNotHasKey('order_id', $query);
    }

    /** @test */
    public function unknown_order_error_callback_web_redirect_has_no_ids(): void
    {
        $this->mockProviderTransport($this->paidVerifyPayload('PAY-GHOST-2', 100.0));

        $response = $this->get(self::ERROR_CALLBACK . '?paymentId=PAY-GHOST-2');

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('payment/failed', $location);
        $this->assertStringNotContainsString('/payment/success', $location);
        $this->assertStringNotContainsString('payment_id', $location);
        $this->assertStringNotContainsString('order_id', $location);
        $this->assertStringNotContainsString('PAY-GHOST-2', $location);
    }

    /** @test */
    public function mismatch_web_redirect_exposes_order_id_only(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'PAY-RC-5');

        // Provider paid a DIFFERENT amount: fail-closed mismatch path.
        $this->mockProviderTransport($this->paidVerifyPayload('PAY-RC-5', 50.0));

        $response = $this->get(self::CALLBACK . '?paymentId=PAY-RC-5');

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('payment/failed', $location);
        $this->assertStringNotContainsString('payment_id', $location);
        $query = $this->redirectQuery($response);
        $this->assertArrayNotHasKey('payment_id', $query);
        $this->assertSame((string) $order->id, $query['order_id'] ?? null);

        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        Event::assertDispatched(PaymentFailed::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function coupon_blocked_web_redirect_exposes_order_id_only(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeKwdOrder(100.0);
        $order->update(['coupon' => 'GHOST-CODE-XYZ']);
        $txn = $this->makePendingTxn($order, 'PAY-RC-6');

        $this->mockProviderTransport($this->paidVerifyPayload('PAY-RC-6', 100.0));

        $response = $this->get(self::CALLBACK . '?paymentId=PAY-RC-6');

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('payment/failed', $location);
        $this->assertStringNotContainsString('payment_id', $location);
        $query = $this->redirectQuery($response);
        $this->assertArrayNotHasKey('payment_id', $query);
        $this->assertSame((string) $order->id, $query['order_id'] ?? null);

        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        Event::assertDispatched(PaymentFailed::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function mobile_json_contract_is_unchanged(): void
    {
        $order = $this->makeKwdOrder(100.0);
        $this->makePendingTxn($order, 'PAY-RC-7');

        $this->mockProviderTransport($this->paidVerifyPayload('PAY-RC-7', 100.0));

        $response = $this->get(self::CALLBACK . '?paymentId=PAY-RC-7&type=mobile');

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'success');
        $response->assertJsonPath('data.payment_id', 'PAY-RC-7');
        $response->assertJsonPath('data.order_id', $order->id);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\DTOs\CheckoutTotals;
use App\Services\Currency\CurrencyService;
use Illuminate\Support\Facades\Event;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * MUST-FIX #4: PayPal external refunds (REFUNDED/REVERSED webhooks).
 *
 * The webhook records the provider-initiated refund in the _refunds ledger
 * (idempotent by provider event id), moves the txn to refunded/partial, and
 * on full refunds applies the same order side effects as a manual full
 * refund (payment-refunded + restore/coupon release). Replays append nothing.
 */
class ExternalRefundTest extends CurrencyTestCase
{
    private const URL = '/api/v1/general/checkout/webhooks/paypal';

    protected function setUp(): void
    {
        parent::setUp();

        config(['payment.gateways.paypal.mode' => 'sandbox']);
        config(['payment.gateways.paypal.client_id' => 'test-client-id']);
        config(['payment.gateways.paypal.client_secret' => 'test-client-secret']);
        config(['payment.gateways.paypal.webhook_id' => 'WH-TEST-123']);
        config(['payment.gateways.paypal.supported_currencies' => ['USD', 'EUR']]);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function makeUsdOrder(float $subtotal = 100.0): Order
    {
        $this->seedCurrencyData();
        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', 'USD')->firstOrFail());
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'USD')->firstOrFail());

        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);
        $order = app(OrderCreationService::class)->createOrder(
            orderData: ['user_id' => $customer->id, 'name' => 'Ext', 'user_phone' => '01000000000', 'user_email' => $customer->email, 'address' => 'x'],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        return $order->fresh();
    }

    private function makePaidCompletedPayPalOrder(string $payId, float $total = 100.0): array
    {
        $order = $this->makeUsdOrder($total);

        $txn = $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'paypal',
            'status' => 'paid',
            'amount' => $total,
            'currency' => 'USD',
            'gateway_transaction_id' => $payId,
            'invoice_id' => $payId,
            'paid_at' => now(),
        ]);

        $order->update([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'paid_at' => now(),
        ]);

        return [$order->fresh(), $txn->fresh()];
    }

    private function bindPayPalVerifier(): void
    {
        $fake = new ExtRefundVerifyClient(['verification_status' => 'SUCCESS']);
        $this->app->instance(
            \App\Services\Payment\PayPalWebhookVerifier::class,
            new \App\Services\Payment\PayPalWebhookVerifier(fn () => $fake),
        );
    }

    private function refundPayload(string $eventId, string $payId, string $value): array
    {
        return [
            'id' => $eventId,
            'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
            'resource' => [
                'id' => 'REFUND-' . $eventId,
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'USD', 'value' => $value],
                'supplementary_data' => ['related_ids' => ['order_id' => $payId]],
            ],
        ];
    }

    private function postPayPal(array $body)
    {
        return $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'transmission-test-id',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => gmdate('Y-m-d\TH:i:s\Z'),
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'test-signature',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/certs/test',
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
        ], (string) json_encode($body));
    }

    /** @test */
    public function external_full_refund_records_ledger_and_side_effects(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        [$order, $txn] = $this->makePaidCompletedPayPalOrder('PAYPAL-EXT-1', 100.0);
        $this->bindPayPalVerifier();

        $response = $this->postPayPal($this->refundPayload('WH-EXT-1', 'PAYPAL-EXT-1', '100.00'));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'refunded');

        $freshTxn = $txn->fresh();
        $this->assertSame('refunded', $freshTxn->status);

        $ledger = $freshTxn->gateway_response['_refunds'] ?? [];
        $this->assertCount(1, $ledger);
        $this->assertSame('WH-EXT-1', $ledger[0]['event_id']);
        $this->assertEqualsWithDelta(100.0, (float) $ledger[0]['amount'], 0.0000001);
        $this->assertTrue((bool) ($ledger[0]['full_refund'] ?? false));

        // Same side effects as a manual full refund.
        $this->assertSame(Order::PAYMENT_STATUS_REFUNDED, $order->fresh()->payment_status);
        $this->assertSame('completed', $order->fresh()->status);
    }

    /** @test */
    public function external_refund_replay_appends_nothing(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        [$order, $txn] = $this->makePaidCompletedPayPalOrder('PAYPAL-EXT-2', 100.0);
        $this->bindPayPalVerifier();

        $body = $this->refundPayload('WH-EXT-2', 'PAYPAL-EXT-2', '100.00');

        $this->postPayPal($body)->assertStatus(200);
        $replay = $this->postPayPal($body);

        $replay->assertStatus(200);
        $replay->assertJsonPath('data.status', 'duplicate');

        $this->assertCount(1, $txn->fresh()->gateway_response['_refunds'] ?? []);
        $this->assertSame('refunded', $txn->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_REFUNDED, $order->fresh()->payment_status);
    }

    /** @test */
    public function external_partial_refund_keeps_order_payable(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        [$order, $txn] = $this->makePaidCompletedPayPalOrder('PAYPAL-EXT-3', 100.0);
        $this->bindPayPalVerifier();

        $response = $this->postPayPal($this->refundPayload('WH-EXT-3', 'PAYPAL-EXT-3', '40.00'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'refunded');

        $this->assertSame('partially_refunded', $txn->fresh()->status);
        // Partial: the order stays payable/completed.
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $order->fresh()->payment_status);
        $this->assertSame('completed', $order->fresh()->status);

        $ledger = $txn->fresh()->gateway_response['_refunds'] ?? [];
        $this->assertCount(1, $ledger);
        $this->assertFalse((bool) ($ledger[0]['full_refund'] ?? true));
    }

    /** @test */
    public function external_refund_for_unknown_transaction_acks_200_ignored(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $this->bindPayPalVerifier();

        $response = $this->postPayPal($this->refundPayload('WH-EXT-9', 'PAYPAL-GHOST', '10.00'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'ignored');
        Event::assertNotDispatched(PaymentSucceeded::class);
    }
}

final class ExtRefundVerifyClient
{
    public function __construct(private array $response) {}

    public function verifyWebHook(array $data): array
    {
        return $this->response;
    }
}

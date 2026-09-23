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
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Transaction;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * PHASE 9 (§25): Stripe + PayPal webhook architecture.
 *
 * No live credentials anywhere: Stripe signatures are generated offline via
 * HMAC against a test webhook secret (verified by the REAL SDK path), the
 * PayPal signature check runs through the injected verifier seam, and
 * gateway re-verification runs through a mocked PaymentGatewayFactory.
 */
class PaymentWebhookTest extends CurrencyTestCase
{
    private const STRIPE_URL = '/api/v1/general/checkout/webhooks/stripe';

    private const PAYPAL_URL = '/api/v1/general/checkout/webhooks/paypal';

    private const STRIPE_SECRET = 'whsec_test_secret_for_webhooks';

    protected function setUp(): void
    {
        parent::setUp();

        // Hermetic baseline regardless of the developer .env.
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

    private function makeUsdOrder(float $subtotal = 100.0): Order
    {
        $this->seedCurrencyData();

        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', 'USD')->firstOrFail());
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'USD')->firstOrFail());

        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Webhook Customer',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 Webhook Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);
        $this->assertSame('USD', $order->fresh()->currency_code);

        return $order->fresh();
    }

    private function makePendingTxn(Order $order, string $gateway, string $ref): Transaction
    {
        return $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => $gateway,
            'status' => 'pending',
            'amount' => (float) $order->total_price,
            'currency' => 'USD',
            'gateway_transaction_id' => $ref,
            'invoice_id' => $ref,
        ]);
    }

    /**
     * Mock gateway re-verification behind the factory seam (the factory
     * resolves disabled gateways too, like the browser callbacks).
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

    private function paidResult(string $ref, float $amount = 100.0): GatewayResult
    {
        return new GatewayResult(
            success: true,
            gatewayTransactionId: $ref,
            amount: $amount,
            currency: 'USD',
            status: 'paid',
            rawResponse: ['id' => $ref, 'status' => 'paid', 'amount' => $amount, 'currency' => 'USD'],
        );
    }

    private function bindPayPalVerifier(array $response): void
    {
        $fake = new FakePayPalVerifyClient($response);

        $this->app->instance(
            PayPalWebhookVerifier::class,
            new PayPalWebhookVerifier(fn () => $fake),
        );
    }

    private function stripeHeader(string $payload, string $secret): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return "t={$timestamp},v1={$signature}";
    }

    private function stripeSessionPayload(string $eventId, string $sessionId, string $type = 'checkout.session.completed'): string
    {
        return (string) json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
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

    private function paypalPayload(string $eventId, string $eventType, array $resource): array
    {
        return [
            'id' => $eventId,
            'event_type' => $eventType,
            'resource' => $resource,
        ];
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

    private function disableStripeViaSettings(): void
    {
        $settings = Settings::query()->first() ?? $this->createSettings();
        $options = is_array($settings->options) ? $settings->options : [];
        $gateways = $options['payment_gateways'] ?? [];
        if (!is_array($gateways)) {
            $gateways = [];
        }
        $gateways['stripe'] = array_merge($gateways['stripe'] ?? [], ['enabled' => false]);
        $options['payment_gateways'] = $gateways;
        $settings->options = $options;
        $settings->save();

        $this->assertFalse(app(\App\Services\Payment\GatewaySettingsService::class)->isEnabled('stripe'));
    }

    // -----------------------------------------------------------------
    // Stripe
    // -----------------------------------------------------------------

    /** @test */
    public function stripe_valid_signature_completes_order(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeUsdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'stripe', 'cs_test_123');

        $this->mockVerify('stripe', 'cs_test_123', $this->paidResult('cs_test_123'));

        $response = $this->postStripe($this->stripeSessionPayload('evt_1', 'cs_test_123'));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
        $this->assertNotNull($txn->fresh()->idempotency_key);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);

        // Event-id dedupe list persisted (capped), allowlisted payload kept.
        $ids = $txn->fresh()->gateway_response['_webhook_event_ids'] ?? null;
        $this->assertIsArray($ids);
        $this->assertContains('evt_1', $ids);
    }

    /** @test */
    public function stripe_bad_signature_is_400_and_leaves_transaction_untouched(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeUsdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'stripe', 'cs_test_456');

        $payload = $this->stripeSessionPayload('evt_bad', 'cs_test_456');

        // Wrong secret → signature mismatch.
        $response = $this->postStripe($payload, 'whsec_wrong_secret');

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);

        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($txn->fresh()->idempotency_key);

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);

        // Missing header is also 400.
        $unsigned = $this->call('POST', self::STRIPE_URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
        $unsigned->assertStatus(400);
        $this->assertSame('pending', $txn->fresh()->status);
    }

    /** @test */
    public function stripe_replay_of_same_event_is_idempotent(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeUsdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'stripe', 'cs_test_789');

        // verifyPayment runs once (first delivery); the replay hits the
        // event-id dedupe list and never re-verifies.
        $this->mockVerify('stripe', 'cs_test_789', $this->paidResult('cs_test_789'), 1);

        $payload = $this->stripeSessionPayload('evt_replay', 'cs_test_789');

        $this->postStripe($payload)->assertStatus(200);
        $replay = $this->postStripe($payload);
        $replay->assertStatus(200);
        $replay->assertJsonPath('data.status', 'ignored');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);
        $this->assertDatabaseCount('payment_reconciliation_results', 0);
    }

    /** @test */
    public function stripe_completes_after_gateway_disabled(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeUsdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'stripe', 'cs_test_disabled');

        // §13: the verify path ignores the enabled flag.
        $this->disableStripeViaSettings();

        $this->mockVerify('stripe', 'cs_test_disabled', $this->paidResult('cs_test_disabled'));

        $response = $this->postStripe($this->stripeSessionPayload('evt_disabled', 'cs_test_disabled'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    /** @test */
    public function stripe_unknown_transaction_acks_200_without_state_change(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $response = $this->postStripe($this->stripeSessionPayload('evt_ghost', 'cs_no_such_txn'));

        // Fail-safe ack: logged, never 500, nothing to verify or complete.
        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'ignored');

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
        $this->assertDatabaseCount('transactions', 0);
    }

    // -----------------------------------------------------------------
    // PayPal
    // -----------------------------------------------------------------

    /** @test */
    public function paypal_capture_completed_completes_order(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeUsdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'paypal', 'PAYPAL-ORDER-1');

        $this->bindPayPalVerifier(['verification_status' => 'SUCCESS']);
        $this->mockVerify('paypal', 'PAYPAL-ORDER-1', $this->paidResult('PAYPAL-ORDER-1'));

        $response = $this->postPayPal($this->paypalPayload('WH-EVT-1', 'PAYMENT.CAPTURE.COMPLETED', [
            'id' => 'CAP-1',
            'status' => 'COMPLETED',
            'invoice_id' => 'PAYPAL-ORDER-1',
            'supplementary_data' => ['related_ids' => ['order_id' => 'PAYPAL-ORDER-1']],
            'amount' => ['currency_code' => 'USD', 'value' => '100.00'],
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function paypal_capture_denied_marks_transaction_failed(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeUsdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'paypal', 'PAYPAL-ORDER-2');

        $this->bindPayPalVerifier(['verification_status' => 'SUCCESS']);

        $response = $this->postPayPal($this->paypalPayload('WH-EVT-2', 'PAYMENT.CAPTURE.DENIED', [
            'id' => 'CAP-2',
            'status' => 'DENIED',
            'supplementary_data' => ['related_ids' => ['order_id' => 'PAYPAL-ORDER-2']],
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'failed');

        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);

        Event::assertDispatched(PaymentFailed::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function paypal_unverified_event_is_rejected_and_leaves_transaction_untouched(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeUsdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'paypal', 'PAYPAL-ORDER-3');

        $body = $this->paypalPayload('WH-EVT-3', 'PAYMENT.CAPTURE.COMPLETED', [
            'id' => 'CAP-3',
            'status' => 'COMPLETED',
            'supplementary_data' => ['related_ids' => ['order_id' => 'PAYPAL-ORDER-3']],
        ]);

        // Provider says the signature is not valid → 401, untouched.
        $this->bindPayPalVerifier(['verification_status' => 'FAILURE']);

        $response = $this->postPayPal($body);

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);

        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);

        // Missing transmission headers → 400, untouched.
        $this->bindPayPalVerifier(['verification_status' => 'SUCCESS']);
        $this->postPayPal($body, false)->assertStatus(400);
        $this->assertSame('pending', $txn->fresh()->status);
    }

    /** @test */
    public function paypal_unknown_transaction_acks_200_logged(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $this->bindPayPalVerifier(['verification_status' => 'SUCCESS']);

        $response = $this->postPayPal($this->paypalPayload('WH-EVT-4', 'PAYMENT.CAPTURE.COMPLETED', [
            'id' => 'CAP-GHOST',
            'status' => 'COMPLETED',
            'supplementary_data' => ['related_ids' => ['order_id' => 'PAYPAL-GHOST']],
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'ignored');

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function paypal_missing_webhook_id_is_503_misconfigured(): void
    {
        config(['payment.gateways.paypal.webhook_id' => '']);

        $response = $this->postPayPal($this->paypalPayload('WH-EVT-5', 'PAYMENT.CAPTURE.COMPLETED', []));

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);
    }
}

/**
 * In-memory stand-in for the SDK verify-webhook-signature call. Records the
 * payload it was given; returns the stubbed verification response.
 */
final class FakePayPalVerifyClient
{
    public ?array $seenPayload = null;

    public function __construct(
        private array $response,
    ) {}

    public function verifyWebHook(array $data): array
    {
        $this->seenPayload = $data;

        return $this->response;
    }
}

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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * Tap webhook architecture — mirror of PaymentWebhookTest for the tap
 * post.url endpoint.
 *
 * No live credentials anywhere: hashstrings are generated offline with the
 * EXACT documented algorithm (developers.tap.company/docs/webhook,
 * expressed independently in tapHash() below), gateway re-verification
 * runs through a mocked PaymentGatewayFactory, and Tap's network is never
 * touched. The real TapWebhookVerifier validates every posted hash.
 */
class TapWebhookTest extends CurrencyTestCase
{
    private const TAP_URL = '/api/v1/general/checkout/webhooks/tap';

    private const TAP_SECRET = 'sk_test_tap_webhook_secret';

    protected function setUp(): void
    {
        parent::setUp();

        // Hermetic baseline regardless of the developer .env.
        config(['payment.gateways.tap.secret_key' => self::TAP_SECRET]);
        config(['payment.gateways.tap.base_url' => 'https://api.tap.company/v2']);
        config(['payment.gateways.tap.source_id' => 'src_all']);
        config(['payment.gateways.tap.supported_currencies' => ['AED', 'BHD', 'EGP', 'EUR', 'GBP', 'JOD', 'KWD', 'OMR', 'QAR', 'SAR', 'USD']]);

        // No feature test may touch the network — the real adapter must
        // never be reached (mock seams or nothing).
        Http::preventStrayRequests();
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
                'name' => 'Tap Customer',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 Tap Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);
        $this->assertSame($currency, $order->fresh()->currency_code);

        return $order->fresh();
    }

    private function makePendingTxn(Order $order, string $ref, ?string $gateway = 'tap', ?string $currency = 'USD'): Transaction
    {
        return $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => $gateway,
            'status' => 'pending',
            'amount' => (float) $order->total_price,
            'currency' => $currency,
            'gateway_transaction_id' => $ref,
            'invoice_id' => $ref,
        ]);
    }

    /**
     * Tap charges webhook body (official example shape — only the fields
     * the verification and flow consume).
     */
    private function tapChargePayload(string $chargeId, string $status, float $amount, string $currency): array
    {
        return [
            'id' => $chargeId,
            'object' => 'charge',
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'reference' => [
                'track' => 'tck_1',
                'payment' => '4327230736106619650',
                'gateway' => 'mada_pg70983e7a-a686-40ba-83e2-c5e9f4074fe5',
            ],
            'transaction' => [
                'created' => '1698392202943',
            ],
        ];
    }

    /**
     * The OFFICIAL hashstring algorithm, expressed independently of the
     * implementation under test. The amount string is explicit per fixture
     * ("100.00" for 2-decimal currencies, "13.255" for KWD) — the doc
     * requires rounding to the currency's standard decimal value.
     */
    private function tapHash(array $payload, string $amountString, ?string $secret = self::TAP_SECRET): string
    {
        $reference = is_array($payload['reference'] ?? null) ? $payload['reference'] : [];
        $transaction = is_array($payload['transaction'] ?? null) ? $payload['transaction'] : [];

        return (string) hash_hmac(
            'sha256',
            'x_id' . (string) $payload['id']
            . 'x_amount' . $amountString
            . 'x_currency' . (string) $payload['currency']
            . 'x_gateway_reference' . (string) ($reference['gateway'] ?? '')
            . 'x_payment_reference' . (string) ($reference['payment'] ?? '')
            . 'x_status' . (string) $payload['status']
            . 'x_created' . (string) ($transaction['created'] ?? ''),
            (string) $secret,
        );
    }

    private function postTap(array $payload, ?string $hash = 'auto', string $header = 'HTTP_HASHSTRING')
    {
        $raw = (string) json_encode($payload);
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($hash !== null) {
            $server[$header] = $hash === 'auto'
                ? $this->tapHash($payload, $this->amountStringFor($payload['amount'], $payload['currency']))
                : $hash;
        }

        // NOTE: postTap must NEVER touch config — tests that mutate gateway
        // configuration do so in the test body, BEFORE calling postTap.
        return $this->call('POST', self::TAP_URL, [], [], [], $server, $raw);
    }

    private function amountStringFor(float $amount, string $currency): string
    {
        return number_format($amount, \App\Services\Payment\CurrencyPrecision::decimalsFor($currency), '.', '');
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

    private function paidResult(string $ref, float $amount, string $currency): GatewayResult
    {
        return new GatewayResult(
            success: true,
            gatewayTransactionId: $ref,
            amount: $amount,
            currency: $currency,
            status: 'paid',
            rawResponse: ['id' => $ref, 'status' => 'CAPTURED', 'amount' => $amount, 'currency' => $currency],
        );
    }

    // -----------------------------------------------------------------
    // Tap
    // -----------------------------------------------------------------

    /** @test */
    public function tap_captured_webhook_completes_order(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'USD');
        $txn = $this->makePendingTxn($order, 'chg_webhook_1');

        $this->mockVerify('tap', 'chg_webhook_1', $this->paidResult('chg_webhook_1', 100.0, 'USD'));

        $payload = $this->tapChargePayload('chg_webhook_1', 'CAPTURED', 100.0, 'USD');

        $response = $this->postTap($payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
        $this->assertNotNull($txn->fresh()->idempotency_key);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);

        // Synthesized dedupe id (no provider event id exists).
        $ids = $txn->fresh()->gateway_response['_webhook_event_ids'] ?? null;
        $this->assertIsArray($ids);
        $this->assertContains('chg_webhook_1:CAPTURED', $ids);
    }

    /** @test */
    public function tap_replayed_captured_webhook_is_idempotent(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'USD');
        $txn = $this->makePendingTxn($order, 'chg_replay_1');

        // Re-verification runs once (first delivery); the replay hits the
        // synthesized event-id dedupe list and never re-verifies.
        $this->mockVerify('tap', 'chg_replay_1', $this->paidResult('chg_replay_1', 100.0, 'USD'), 1);

        $payload = $this->tapChargePayload('chg_replay_1', 'CAPTURED', 100.0, 'USD');

        $this->postTap($payload)->assertStatus(200);
        $replay = $this->postTap($payload);

        $replay->assertStatus(200);
        $replay->assertJsonPath('data.status', 'ignored');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);
        $this->assertDatabaseCount('payment_reconciliation_results', 0);
    }

    /** @test */
    public function tap_bad_or_unsigned_hash_is_rejected_and_state_untouched(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'USD');
        $txn = $this->makePendingTxn($order, 'chg_badhash_1');

        $payload = $this->tapChargePayload('chg_badhash_1', 'CAPTURED', 100.0, 'USD');

        // Hash produced with a DIFFERENT secret → mismatch → 401.
        $wrongSecretHash = $this->tapHash($payload, '100.00', 'sk_test_wrong_secret');
        $this->postTap($payload, $wrongSecretHash)->assertStatus(401);

        // Tampered status after signing → mismatch → 401.
        $tampered = $payload;
        $tampered['status'] = 'FAILED';
        $validForOriginal = $this->tapHash($payload, '100.00');
        $this->postTap($tampered, $validForOriginal)->assertStatus(401);

        // Unsigned → 400.
        $this->postTap($payload, null)->assertStatus(400);

        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function tap_failed_webhook_marks_transaction_failed(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'USD');
        $txn = $this->makePendingTxn($order, 'chg_failed_1');

        $payload = $this->tapChargePayload('chg_failed_1', 'FAILED', 100.0, 'USD');

        $response = $this->postTap($payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'failed');

        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        // Failure paths keep the idempotency token null so a later CAPTURED
        // (e.g. out-of-order notification) can still complete.
        $this->assertNull($txn->fresh()->idempotency_key);

        Event::assertDispatchedTimes(PaymentFailed::class, 1);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function tap_failed_then_captured_out_of_order_still_completes(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'USD');
        $txn = $this->makePendingTxn($order, 'chg_ooo_1');

        // Bind the verification mock BEFORE the first request: Laravel caches
        // the resolved controller instance on the Route object
        // (Illuminate\Routing\Route::getController(), L269-278) and in-process
        // feature requests reuse it — a factory binding made AFTER request 1
        // never reaches the controller (the real adapter would run and hit
        // preventStrayRequests instead). The FAILED webhook never calls the
        // factory, so the single times(1) expectation is consumed by step 2.
        $this->mockVerify('tap', 'chg_ooo_1', $this->paidResult('chg_ooo_1', 100.0, 'USD'));

        // 1) The failure notification lands first (no factory call happens —
        //    failure marking trusts the verified hashstring).
        $failed = $this->tapChargePayload('chg_ooo_1', 'FAILED', 100.0, 'USD');
        $this->postTap($failed)->assertStatus(200);
        $this->assertSame('failed', $txn->fresh()->status);

        // 2) The (delayed) CAPTURED notification then completes the payment.
        $captured = $this->tapChargePayload('chg_ooo_1', 'CAPTURED', 100.0, 'USD');
        $response = $this->postTap($captured);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    /** @test */
    public function tap_initiated_webhook_is_ignored_and_state_untouched(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'USD');
        $txn = $this->makePendingTxn($order, 'chg_initiated_1');

        $payload = $this->tapChargePayload('chg_initiated_1', 'INITIATED', 100.0, 'USD');

        $response = $this->postTap($payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'ignored');

        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function tap_hash_header_fallback_is_accepted_when_hashstring_absent(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'USD');
        $txn = $this->makePendingTxn($order, 'chg_hashfb_1');

        // The official example posts BOTH headers; only `hashstring` is
        // documented, so `hash` is accepted ONLY when hashstring is absent.
        // A completed order proves the hash-header value was verified.
        $this->mockVerify('tap', 'chg_hashfb_1', $this->paidResult('chg_hashfb_1', 100.0, 'USD'));

        $payload = $this->tapChargePayload('chg_hashfb_1', 'CAPTURED', 100.0, 'USD');

        $response = $this->postTap($payload, 'auto', 'HTTP_HASH');

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'completed');
        $this->assertSame('paid', $txn->fresh()->status);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    /** @test */
    public function tap_invalid_charge_id_is_400_and_state_untouched(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'USD');
        $txn = $this->makePendingTxn($order, 'chg_bad id!');

        // A VALID hash for an id that fails the controller's reference
        // charset bound: reaches the charge-id branch, never the factory.
        $payload = $this->tapChargePayload('chg_bad id!', 'CAPTURED', 100.0, 'USD');

        $response = $this->postTap($payload);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);

        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function tap_unrecognized_non_terminal_status_is_ignored(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'USD');
        $txn = $this->makePendingTxn($order, 'chg_future_1');

        // A status outside Tap's documented set (forward-compatibility):
        // neither CAPTURED nor a listed terminal failure → 200 ignored.
        $payload = $this->tapChargePayload('chg_future_1', 'SOME_NEW_STATUS', 100.0, 'USD');

        $response = $this->postTap($payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'ignored');

        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function tap_unknown_charge_acks_200_without_state_change(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $payload = $this->tapChargePayload('chg_no_such_txn', 'CAPTURED', 100.0, 'USD');

        $response = $this->postTap($payload);

        // Fail-safe ack: logged, never 500, nothing to verify or complete.
        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'ignored');

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
        $this->assertDatabaseCount('transactions', 0);
    }

    /** @test */
    public function tap_misconfigured_secret_is_503(): void
    {
        config(['payment.gateways.tap.secret_key' => '']);

        $payload = $this->tapChargePayload('chg_misconf', 'CAPTURED', 100.0, 'USD');

        $response = $this->postTap($payload, $this->tapHash($payload, '100.00'));

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function tap_amount_mismatch_blocks_completion(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'USD');
        $txn = $this->makePendingTxn($order, 'chg_mismatch_1');

        // The provider-verified amount disagrees with the order snapshot —
        // completion must be refused and the mismatch recorded fail-closed.
        $this->mockVerify('tap', 'chg_mismatch_1', $this->paidResult('chg_mismatch_1', 999.0, 'USD'));

        $payload = $this->tapChargePayload('chg_mismatch_1', 'CAPTURED', 100.0, 'USD');

        $response = $this->postTap($payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'failed');

        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNotSame('success', $order->fresh()->payment_status);

        Event::assertDispatchedTimes(PaymentFailed::class, 1);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function tap_webhook_for_a_foreign_gateway_transaction_is_rejected(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'USD');
        $txn = $this->makePendingTxn($order, 'chg_foreign_1', gateway: 'myfatoorah');

        $payload = $this->tapChargePayload('chg_foreign_1', 'CAPTURED', 100.0, 'USD');

        $response = $this->postTap($payload);

        // Binding check: the referenced transaction belongs to another
        // gateway — reject before any provider call.
        $response->assertStatus(400);
        $response->assertJsonPath('success', false);

        $this->assertSame('pending', $txn->fresh()->status);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function tap_kwd_order_completes_with_three_decimal_amount(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        // 13.255 KWD (3-decimal currency) end-to-end through the webhook.
        $order = $this->makeOrder(13.255, 'KWD');
        $txn = $this->makePendingTxn($order, 'chg_kwd_1', currency: 'KWD');

        $this->mockVerify('tap', 'chg_kwd_1', $this->paidResult('chg_kwd_1', 13.255, 'KWD'));

        $payload = $this->tapChargePayload('chg_kwd_1', 'CAPTURED', 13.255, 'KWD');

        $response = $this->postTap($payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }
}

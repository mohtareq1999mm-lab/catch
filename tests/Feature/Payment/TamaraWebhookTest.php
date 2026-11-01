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
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * Tamara webhook architecture — mirror of TabbyWebhookTest for Tamara's
 * documented self-service delivery (query string AND Authorization: Bearer
 * carry `tamaraToken`, an HS256 JWT signed with the merchant's separate
 * Notification Token).
 *
 * Every delivery is authenticated with the REAL TamaraWebhookVerifier and
 * REAL HS256 tokens produced with Firebase\JWT\JWT — no simplistic header
 * compare. Gateway re-verification runs through a mocked
 * PaymentGatewayFactory; Tamara's network is never touched. UNKNOWN event
 * names hit the suffix-matched families (approved|authorised|captured /
 * refund / canceled|cancelled|declined|expired); the exact provider event
 * strings remain UNVERIFIED until a live sandbox delivery (see audit).
 *
 * Contract notes (docs.tamara.co/docs/transaction-authorisation):
 * - the tamaraToken payload itself is NEVER trusted for money decisions —
 *   conclusive families route through the server-side fresh read (mockVerify);
 * - unknown order id => HTTP 404 so Tamara retries (deliberate D3; orders
 *   are always created before payment sessions in this repo);
 * - refunds reconcile ONLY on a paid row through
 *   PaymentRefundService::recordExternalRefund with the per-refund dedupe
 *   id "tamara:refund:{txnId}:{refundId}";
 * - captured-family deliveries additionally append the `_captures` ledger
 *   (bookkeeping only — never completion state).
 *
 * STATUS: DB-backed (RefreshDatabase) — NOT RUNNABLE in the environment
 * where MySQL is unreachable (see
 * docs/audits/tamara-payment-integration-audit.md §Tests). Runnable where a
 * MySQL test database exists:
 *
 *   php vendor\phpunit\phpunit\phpunit tests\Feature\Payment\TamaraWebhookTest.php
 */
class TamaraWebhookTest extends CurrencyTestCase
{
    private const TAMARA_URL = '/api/v1/general/checkout/webhooks/tamara';

    private const TAMARA_TOKEN = 'tamara_webhook_api_token';

    private const NOTIFICATION_TOKEN = 'test_tamara_notification_jwt_secret';

    protected function setUp(): void
    {
        parent::setUp();

        // Hermetic baseline regardless of the developer .env.
        config(['payment.gateways.tamara.class' => \App\Services\Gateway\TamaraGateway::class]);
        config(['payment.gateways.tamara.enabled' => true]);
        config(['payment.gateways.tamara.environment' => 'sandbox']);
        config(['payment.gateways.tamara.api_url' => null]);
        config(['payment.gateways.tamara.api_token' => self::TAMARA_TOKEN]);
        config(['payment.gateways.tamara.notification_token' => self::NOTIFICATION_TOKEN]);
        config(['payment.gateways.tamara.supported_currencies' => ['SAR', 'AED', 'BHD', 'KWD', 'OMR']]);
        config(['payment.gateways.tamara.eligibility_timeout_ms' => 200]);
        config(['payment.gateways.tamara.capture_attempts' => 2]);

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
        $this->seedCurrencyData(); // USD + KWD + SAR (seeded set) — SAR is Tamara-supported

        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', $currency)->firstOrFail());
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', $currency)->firstOrFail());

        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Tamara Customer',
                'user_phone' => '0555000001',
                'user_email' => $customer->email,
                'address' => '123 Tamara Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);
        $this->assertSame($currency, $order->fresh()->currency_code);

        return $order->fresh();
    }

    private function makePendingTxn(Order $order, string $ref, ?string $gateway = 'tamara', ?string $currency = 'SAR'): Transaction
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

    private function makePaidTxn(Order $order, string $ref, string $currency = 'SAR'): Transaction
    {
        $txn = new Transaction([
            'user_id' => $order->user_id,
            'payment_method' => 'tamara',
            'status' => 'paid',
            'amount' => (float) $order->total_price,
            'currency' => $currency,
            'gateway_transaction_id' => $ref,
            'invoice_id' => $ref,
            'paid_at' => now(),
        ]);
        $order->transactions()->save($txn);

        return $txn;
    }

    /**
     * Tamara webhook body — the documented delivery envelope
     * {order_id, order_reference_id, order_number, event_type, data{}}.
     */
    private function tamaraPayload(string $orderId, string $eventType, array $data = [], array $extra = []): array
    {
        return array_merge([
            'order_id' => $orderId,
            'order_reference_id' => 'ref-' . substr($orderId, 0, 8),
            'order_number' => 'MEEM-1',
            'event_type' => $eventType,
            'data' => $data,
        ], $extra);
    }

    private function hs256Token(?int $ttl = 300): string
    {
        return JWT::encode([
            'exp' => time() + ($ttl ?? 300),
            'iat' => time(),
            'iss' => 'Tamara',
        ], self::NOTIFICATION_TOKEN, 'HS256');
    }

    /**
     * Deliver a webhook body to the Tamara endpoint. `tamaraToken` rides
     * the query string by default ('auto' = a valid token); a non-null
     * Bearer adds the Authorization channel (conflict cases pass a
     * DIFFERENT token there).
     */
    private function postTamara(array $payload, ?string $queryToken = 'auto', ?string $bearer = null, ?int $tokenTtl = 300)
    {
        $raw = (string) json_encode($payload);
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($bearer !== null && $bearer !== '') {
            $bearerValue = $bearer === 'auto' ? $this->hs256Token($tokenTtl) : $bearer;
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearerValue;
        }

        $uri = self::TAMARA_URL;

        if ($queryToken !== null) {
            $token = $queryToken === 'auto' ? $this->hs256Token($tokenTtl) : $queryToken;
            $uri .= '?tamaraToken=' . urlencode($token);
        }

        // NOTE: postTamara must NEVER touch config — tests that mutate the
        // gateway configuration do so BEFORE calling postTamara.
        return $this->call('POST', $uri, [], [], [], $server, $raw);
    }

    /**
     * Mock gateway re-verification behind the factory seam. $makeTimes is
     * explicit because Tamara's capture-trail recording performs a SECOND
     * make() + verifyPayment() from a FRESH read after a captured-family
     * delivery (see recordTamaraCaptures).
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

    /**
     * Provider-VERIFIED fresh read. The rawResponse mirrors the real
     * adapter's sanitizeOrder allowlist (order keys + captures/refunds
     * evidence) so recordTamaraCaptures/recordTamaraRefunds read exactly
     * what they would read from the real TamaraGateway.
     */
    private function paidResult(string $ref, float $amount, string $currency, array $extraRaw = []): GatewayResult
    {
        return new GatewayResult(
            success: true,
            gatewayTransactionId: $ref,
            amount: $amount,
            currency: $currency,
            status: 'paid',
            rawResponse: array_merge([
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
            ], $extraRaw),
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
    // Webhook flow
    // -----------------------------------------------------------------

    /** @test */
    public function tamara_authorised_webhook_completes_only_through_server_verification(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, '870e6c88-0001-4a44-9ec0-7f7b8ed65e0c');

        // The delivery event (`order_authorised`) is an untrusted wake-up:
        // the mocked FRESH read decides completion.
        $this->mockVerify(
            'tamara',
            $txn->gateway_transaction_id,
            1,
            $this->paidResult($txn->gateway_transaction_id, 100.0, 'SAR'),
        );

        $response = $this->postTamara($this->tamaraPayload($txn->gateway_transaction_id, 'order_authorised', [
            'status' => 'authorised',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
        $this->assertNotNull($txn->fresh()->idempotency_key);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);

        // Synthesized dedupe id (Tamara documents no unique delivery id).
        $ids = $txn->fresh()->gateway_response['_webhook_event_ids'] ?? null;
        $this->assertIsArray($ids);
        $this->assertContains('order_authorised:' . $txn->gateway_transaction_id, $ids);
    }

    /** @test */
    public function tamara_replayed_webhook_is_idempotent(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, '870e6c88-0002-4a44-9ec0-7f7b8ed65e0c');

        // Re-verification runs once (first delivery); the replay hits the
        // synthesized event-id dedupe list and never re-verifies.
        $this->mockVerify(
            'tamara',
            $txn->gateway_transaction_id,
            1,
            $this->paidResult($txn->gateway_transaction_id, 100.0, 'SAR'),
        );

        $payload = $this->tamaraPayload($txn->gateway_transaction_id, 'order_authorised', ['status' => 'authorised']);

        $this->postTamara($payload)->assertStatus(200);
        $replay = $this->postTamara($payload);

        $replay->assertStatus(200);
        $replay->assertJsonPath('data.status', 'ignored');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function tamara_unverified_or_missing_tamaraToken_is_401_and_state_untouched(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, '870e6c88-0003-4a44-9ec0-7f7b8ed65e0c');

        $payload = $this->tamaraPayload($txn->gateway_transaction_id, 'order_authorised', ['status' => 'authorised']);

        // Garbage token → 401.
        $this->postTamara($payload, 'not-a-jwt')->assertStatus(401);

        // Token signed with a DIFFERENT secret → 401 (wrong environment /
        // rotated Notification Token).
        $foreign = JWT::encode(['exp' => time() + 300, 'iat' => time()], 'wrong_tamara_secret', 'HS256');
        $this->postTamara($payload, $foreign)->assertStatus(401);

        // Expired token → 401.
        $this->postTamara($payload, 'auto', tokenTtl: -1)->assertStatus(401);

        // Unsigned (no query, no Bearer) → 401.
        $this->postTamara($payload, null)->assertStatus(401);

        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function tamara_dual_channel_token_conflict_is_401(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, '870e6c88-0004-4a44-9ec0-7f7b8ed65e0c');

        // Both documented channels present with DIFFERENT tokens (differing
        // claim sets so the strings genuinely diverge): one of them could be
        // attacker-controlled — the delivery is never a tamper-free shape.
        // Fail closed BEFORE any gateway correlation.
        $conflictBearer = JWT::encode([
            'exp' => time() + 300,
            'iat' => time(),
            'iss' => 'Tamara',
            'jti' => 'conflicting-channel',
        ], self::NOTIFICATION_TOKEN, 'HS256');

        $response = $this->postTamara(
            $this->tamaraPayload($txn->gateway_transaction_id, 'order_authorised', ['status' => 'authorised']),
            'auto',
            $conflictBearer,
        );

        $response->assertStatus(401);

        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function tamara_declined_webhook_marks_pending_row_failed(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, '870e6c88-0005-4a44-9ec0-7f7b8ed65e0c');

        // The payload's `declined` is only a wake-up: the FRESH server read
        // must itself show a terminal provider state before the row fails.
        $this->mockVerify(
            'tamara',
            $txn->gateway_transaction_id,
            1,
            $this->failedResult($txn->gateway_transaction_id, 100.0, 'SAR'),
        );

        $response = $this->postTamara($this->tamaraPayload($txn->gateway_transaction_id, 'order_declined', [
            'status' => 'declined',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'failed');

        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        // Failure paths keep the idempotency token null so a later
        // out-of-order completion can still proceed if it is legitimate.
        $this->assertNull($txn->fresh()->idempotency_key);

        Event::assertDispatchedTimes(PaymentFailed::class, 1);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function tamara_captured_event_completes_and_records_the_provider_capture_trail(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, '870e6c88-0006-4a44-9ec0-7f7b8ed65e0c');

        // 1st fresh read completes the authorisation; 2nd fresh read (the
        // capture-trail helper) sees the provider capture evidence.
        $this->mockVerify(
            'tamara',
            $txn->gateway_transaction_id,
            2,
            $this->paidResult($txn->gateway_transaction_id, 100.0, 'SAR'),
            $this->paidResult($txn->gateway_transaction_id, 100.0, 'SAR', [
                'status' => 'fully_captured',
                'captures' => [['capture_id' => 'cap_web_1', 'amount' => 100.0, 'created' => '2026-10-31T00:00:00Z']],
            ]),
        );

        $response = $this->postTamara($this->tamaraPayload($txn->gateway_transaction_id, 'order_captured', [
            'status' => 'fully_captured',
            'capture_id' => 'cap_web_1',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);

        // The `_captures` ledger (bookkeeping only) recorded the provider
        // capture from the FRESH read, never from the delivery payload.
        $raw = (array) $txn->fresh()->gateway_response;
        $captures = $raw['_captures'] ?? [];
        $this->assertNotEmpty($captures);
        $this->assertSame('cap_web_1', $captures[0]['capture_id']);
        $this->assertSame('webhook', $captures[0]['source']);

        // Dedupe id carries the payload-echoed capture id suffix.
        $this->assertContains(
            'order_captured:' . $txn->gateway_transaction_id . ':cap_web_1',
            $raw['_webhook_event_ids'] ?? [],
        );

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    // -----------------------------------------------------------------
    // Provider-initiated refund reconciliation (merchant dashboard)
    // -----------------------------------------------------------------

    /** @test */
    public function tamara_refund_webhook_reconciles_the_ledger_of_a_paid_row(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $order->forceFill([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ])->save();
        $txn = $this->makePaidTxn($order, '870e6c88-0007-4a44-9ec0-7f7b8ed65e0c');

        // ONE refund delivery: fresh read reconciles the external refund.
        $this->mockVerify(
            'tamara',
            $txn->gateway_transaction_id,
            1,
            $this->paidResult($txn->gateway_transaction_id, 100.0, 'SAR', [
                'refunds' => [['refund_id' => 'refund_ext_1', 'amount' => 100.0, 'created' => '2026-10-31T01:00:00Z']],
            ]),
        );

        $response = $this->postTamara($this->tamaraPayload($txn->gateway_transaction_id, 'order_refunded', [
            'status' => 'fully_refunded',
        ]));

        $response->assertStatus(200);

        // Full external refund side effects (same as a manual refund).
        $this->assertSame(Order::PAYMENT_STATUS_REFUNDED, $order->fresh()->payment_status);
        $this->assertSame('refunded', $txn->fresh()->status);

        // Per-refund dedupe id in the txn ledger.
        $ledger = (array) ($txn->fresh()->gateway_response['_refunds'] ?? []);
        $this->assertNotEmpty($ledger);
        $this->assertSame('tamara:refund:' . $txn->id . ':refund_ext_1', $ledger[0]['event_id']);
        $this->assertSame('external_refund', $ledger[0]['provider_status']);
        $this->assertSame(100.0, (float) $ledger[0]['amount']);
    }

    /** @test */
    public function tamara_refund_replays_never_double_apply_the_refund(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $order->forceFill([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ])->save();
        $txn = $this->makePaidTxn($order, '870e6c88-0008-4a44-9ec0-7f7b8ed65e0c');

        // Each delivery fresh-reads the provider (refund row gate) but the
        // per-refund dedupe id folds the replay to a no-op against money.
        $this->mockVerify(
            'tamara',
            $txn->gateway_transaction_id,
            2,
            $this->paidResult($txn->gateway_transaction_id, 100.0, 'SAR', [
                'refunds' => [['refund_id' => 'refund_ext_2', 'amount' => 100.0, 'created' => '2026-10-31T01:00:00Z']],
            ]),
            $this->paidResult($txn->gateway_transaction_id, 100.0, 'SAR', [
                'refunds' => [['refund_id' => 'refund_ext_2', 'amount' => 100.0, 'created' => '2026-10-31T01:00:00Z']],
            ]),
        );

        $payload = $this->tamaraPayload($txn->gateway_transaction_id, 'order_refunded', ['status' => 'fully_refunded']);

        $this->postTamara($payload)->assertStatus(200);
        $this->postTamara($payload)->assertStatus(200);

        // Idempotent replay: ONE ledger entry, refund side effects applied
        // exactly once.
        $ledger = (array) ($txn->fresh()->gateway_response['_refunds'] ?? []);
        $this->assertCount(1, $ledger);
        $this->assertSame(Order::PAYMENT_STATUS_REFUNDED, $order->fresh()->payment_status);
        $this->assertStringContainsString('refund_ext_2', json_encode($ledger));
    }

    /** @test */
    public function tamara_later_distinct_refunds_reconcile_after_the_first_flips_the_row(): void
    {
        // M2: the first external refund flips the row to
        // partially_refunded — later DISTINCT refund deliveries must still
        // reconcile ('paid'-only gating would drop them forever).
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $order->forceFill([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ])->save();
        $txn = $this->makePaidTxn($order, '870e6c88-0009-4a44-9ec0-7f7b8ed65e0c');

        $this->mockVerify(
            'tamara',
            $txn->gateway_transaction_id,
            2,
            $this->paidResult($txn->gateway_transaction_id, 100.0, 'SAR', [
                'refunds' => [['refund_id' => 'refund_chain_a', 'amount' => 40.0, 'created' => '2026-10-31T01:00:00Z']],
            ]),
            $this->paidResult($txn->gateway_transaction_id, 100.0, 'SAR', [
                'refunds' => [['refund_id' => 'refund_chain_b', 'amount' => 60.0, 'created' => '2026-10-31T02:00:00Z']],
            ]),
        );

        $first = $this->postTamara($this->tamaraPayload($txn->gateway_transaction_id, 'order_refunded'));
        $first->assertStatus(200);

        $this->assertSame('partially_refunded', $txn->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $order->fresh()->payment_status);

        $second = $this->postTamara($this->tamaraPayload($txn->gateway_transaction_id, 'order_refunded'));
        $second->assertStatus(200);

        $ledger = (array) ($txn->fresh()->gateway_response['_refunds'] ?? []);
        $this->assertCount(2, $ledger);
        $this->assertSame('tamara:refund:' . $txn->id . ':refund_chain_a', $ledger[0]['event_id']);
        $this->assertSame('tamara:refund:' . $txn->id . ':refund_chain_b', $ledger[1]['event_id']);
        $this->assertSame('refunded', $txn->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_REFUNDED, $order->fresh()->payment_status);
    }

    /** @test */
    public function tamara_refunds_on_a_non_paid_row_are_deferred_not_recorded(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, '870e6c88-0010-4a44-9ec0-7f7b8ed65e0c');

        // A refund delivery for money this system has not seen paid: the
        // controller's refund gate runs BEFORE any fresh read (pending is
        // not in the refundable set), so no adapter call exists and the
        // refund stays DEFERRED to a later wake-up after completion.
        $response = $this->postTamara($this->tamaraPayload($txn->gateway_transaction_id, 'order_refunded', [
            'status' => 'refunded',
        ]));

        $response->assertStatus(200);

        $fresh = $txn->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertArrayNotHasKey('_refunds', (array) $fresh->gateway_response);
        $this->assertNotSame(Order::PAYMENT_STATUS_REFUNDED, $order->fresh()->payment_status);

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function tamara_stale_decline_delivery_after_a_landed_authorisation_completes_from_the_fresh_read(): void
    {
        // Crash-window recovery (D1-R): authorisation landed but the local
        // row is still pending; a DELAYED terminal-failure delivery
        // generated before the authorisation must never terminally fail
        // money Tamara took — the fresh server read arbitrates BOTH ways.
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, '870e6c88-0011-4a44-9ec0-7f7b8ed65e0c');

        $this->mockVerify(
            'tamara',
            $txn->gateway_transaction_id,
            1,
            $this->paidResult($txn->gateway_transaction_id, 100.0, 'SAR'),
        );

        $response = $this->postTamara($this->tamaraPayload($txn->gateway_transaction_id, 'order_expired'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'completed');

        $this->assertSame('paid', $txn->fresh()->status);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNotNull($txn->fresh()->idempotency_key);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function tamara_unknown_order_ref_is_404_so_tamara_retries(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        // DELIBERATE D3 deviation: a real Tamara payment must already exist
        // locally (orders are created BEFORE the payment session), so an
        // unknown id requests a provider RETRY (non-200) — usually a racing
        // commit, and a persistent 404 stays ops-visible.
        $response = $this->postTamara($this->tamaraPayload('870e6c88-no-such-order', 'order_authorised'));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function tamara_webhook_for_a_foreign_gateway_transaction_is_rejected(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, '870e6c88-0012-4a44-9ec0-7f7b8ed65e0c', gateway: 'myfatoorah');

        $response = $this->postTamara($this->tamaraPayload($txn->gateway_transaction_id, 'order_authorised'));

        // Binding check: the referenced transaction belongs to another
        // gateway — reject before any provider call.
        $response->assertStatus(400);
        $response->assertJsonPath('success', false);

        $this->assertSame('pending', $txn->fresh()->status);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function tamara_misconfigured_tokens_are_503(): void
    {
        config(['payment.gateways.tamara.notification_token' => '']);

        $payload = $this->tamaraPayload('870e6c88-0013-4a44-9ec0-7f7b8ed65e0c', 'order_authorised');

        $response = $this->postTamara($payload);

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function tamara_amount_mismatch_blocks_completion(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, '870e6c88-0014-4a44-9ec0-7f7b8ed65e0c');

        // The provider-verified amount disagrees with the order snapshot —
        // completion must be refused and the mismatch recorded fail-closed.
        $this->mockVerify(
            'tamara',
            $txn->gateway_transaction_id,
            1,
            $this->paidResult($txn->gateway_transaction_id, 999.0, 'SAR'),
        );

        $response = $this->postTamara($this->tamaraPayload($txn->gateway_transaction_id, 'order_authorised', [
            'status' => 'authorised',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'failed');

        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNotSame('success', $order->fresh()->payment_status);

        Event::assertDispatchedTimes(PaymentFailed::class, 1);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function tamara_malformed_or_empty_body_is_400(): void
    {
        $token = $this->hs256Token();

        // Empty body.
        $this->call('POST', self::TAMARA_URL . '?tamaraToken=' . urlencode($token), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '')->assertStatus(400);

        // Invalid JSON.
        $this->call('POST', self::TAMARA_URL . '?tamaraToken=' . urlencode($token), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{not-json')->assertStatus(400);
    }
}

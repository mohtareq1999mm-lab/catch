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
 * Tabby webhook architecture — mirror of TapWebhookTest for the Tabby
 * self-service webhook endpoint, plus the D1-R recovery semantics.
 *
 * Verification = the registered custom auth header Tabby echoes on every
 * delivery (header NAME from TABBY_WEBHOOK_HEADER, VALUE from
 * TABBY_WEBHOOK_SECRET). No hashing on the payment-webhook family. Every
 * delivery is authenticated with the REAL TabbyWebhookVerifier; gateway
 * re-verification runs through a mocked PaymentGatewayFactory; Tabby's
 * network is never touched.
 *
 * Contract notes (docs.tabby.ai/payment-processing + webhooks):
 * - payload statuses are LOWERCASE (webhook) vs UPPERCASE (retrieve);
 * - payload status is NEVER trusted — conclusive events route through the
 *   server-side fresh read (mockVerify here);
 * - unknown payment id => HTTP 404 so Tabby retries (deliberate D3;
 *   orders are always created before payment sessions in this repo);
 * - refunds in the payload reconcile ONLY on a paid row through
 *   PaymentRefundService::recordExternalRefund with the per-refund dedupe
 *   id "tabby:refund:{txnId}:{refundId}".
 *
 * STATUS: DB-backed (RefreshDatabase) — NOT RUNNABLE in the environment
 * where MySQL is unreachable (see docs/audits/tabby-payment-integration-audit.md
 * §Tests). Runnable where a MySQL test database exists:
 *
 *   php vendor\phpunit\phpunit\phpunit tests\Feature\Payment\TabbyWebhookTest.php
 */
class TabbyWebhookTest extends CurrencyTestCase
{
    private const TABBY_URL = '/api/v1/general/checkout/webhooks/tabby';

    private const TABBY_HEADER = 'HTTP_X_TABBY_SIGNATURE';

    private const TABBY_SECRET = 'test_webhook_shared_secret';

    protected function setUp(): void
    {
        parent::setUp();

        // Hermetic baseline regardless of the developer .env.
        config(['payment.gateways.tabby.class' => \App\Services\Gateway\TabbyGateway::class]);
        config(['payment.gateways.tabby.enabled' => true]);
        config(['payment.gateways.tabby.secret_key' => 'sk_test_tabby_webhook_secret']);
        config(['payment.gateways.tabby.base_url' => 'https://api.tabby.ai/api/v2']);
        config(['payment.gateways.tabby.merchant_code' => 'tabby_merchant_flow']);
        config(['payment.gateways.tabby.webhook_header' => 'x-tabby-signature']);
        config(['payment.gateways.tabby.webhook_secret' => self::TABBY_SECRET]);
        config(['payment.gateways.tabby.supported_currencies' => ['AED', 'SAR']]);
        config(['payment.gateways.tabby.capture_attempts' => 2]);
        config(['payment.gateways.tabby.capture_retry_delay_ms' => 0]);

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
        $this->seedCurrencyData(); // USD + KWD + SAR (seeded set) — SAR is Tabby-supported

        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', $currency)->firstOrFail());
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', $currency)->firstOrFail());

        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Tabby Customer',
                'user_phone' => '0555000001',
                'user_email' => $customer->email,
                'address' => '123 Tabby Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);
        $this->assertSame($currency, $order->fresh()->currency_code);

        return $order->fresh();
    }

    private function makePendingTxn(Order $order, string $ref, ?string $gateway = 'tabby', ?string $currency = 'SAR'): Transaction
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
     * Tabby webhook body — the payment object (only the fields the flow
     * consumes); statuses LOWERCASE per the webhook docs.
     */
    private function tabbyPayload(string $paymentId, string $status, float $amount, string $currency, array $extra = []): array
    {
        return array_merge([
            'id' => $paymentId,
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'captures' => [],
            'refunds' => [],
        ], $extra);
    }

    private function postTabby(array $payload, ?string $HeaderValue = 'auto', string $header = self::TABBY_HEADER)
    {
        $raw = (string) json_encode($payload);
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($HeaderValue !== null) {
            $server[$header] = $HeaderValue === 'auto' ? self::TABBY_SECRET : $HeaderValue;
        }

        // NOTE: postTabby must NEVER touch config — tests that mutate the
        // gateway configuration do so BEFORE calling postTabby.
        return $this->call('POST', self::TABBY_URL, [], [], [], $server, $raw);
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

    private function paidResult(string $ref, float $amount, string $currency): GatewayResult
    {
        return new GatewayResult(
            success: true,
            gatewayTransactionId: $ref,
            amount: $amount,
            currency: $currency,
            status: 'paid',
            rawResponse: ['id' => $ref, 'status' => 'CLOSED', 'amount' => $amount, 'currency' => $currency, 'captures' => [['id' => 'cap_web_1']]],
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
            rawResponse: ['_capture' => ['reference_id' => 'capture-' . $ref, 'attempts' => 3, 'outcome' => 'unresolved']],
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
            errorMessage: 'Payment declined by provider',
        );
    }

    // -----------------------------------------------------------------
    // Webhook flow
    // -----------------------------------------------------------------

    /** @test */
    public function tabby_authorized_webhook_completes_only_through_server_verification(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, 'pay_webhook_1');

        // The payload status (`authorized`) is an untrusted wake-up: the
        // mocked FRESH read decides completion.
        $this->mockVerify('tabby', 'pay_webhook_1', $this->paidResult('pay_webhook_1', 100.0, 'SAR'));

        $response = $this->postTabby($this->tabbyPayload('pay_webhook_1', 'authorized', 100.0, 'SAR'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
        $this->assertNotNull($txn->fresh()->idempotency_key);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);

        // Synthesized dedupe id (no provider event id exists on Tabby).
        $ids = $txn->fresh()->gateway_response['_webhook_event_ids'] ?? null;
        $this->assertIsArray($ids);
        $this->assertContains('pay_webhook_1:authorized', $ids);
    }

    /** @test */
    public function tabby_replayed_webhook_is_idempotent(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, 'pay_replay_1');

        // Re-verification runs once (first delivery); the replay hits the
        // synthesized event-id dedupe list and never re-verifies.
        $this->mockVerify('tabby', 'pay_replay_1', $this->paidResult('pay_replay_1', 100.0, 'SAR'));

        $payload = $this->tabbyPayload('pay_replay_1', 'authorized', 100.0, 'SAR');

        $this->postTabby($payload)->assertStatus(200);
        $replay = $this->postTabby($payload);

        $replay->assertStatus(200);
        $replay->assertJsonPath('data.status', 'ignored');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function tabby_unverified_or_mismatched_header_is_rejected_and_state_untouched(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, 'pay_badheader_1');

        $payload = $this->tabbyPayload('pay_badheader_1', 'authorized', 100.0, 'SAR');

        // Value produced with a DIFFERENT secret → mismatch → 401.
        $this->postTabby($payload, 'a_different_secret')->assertStatus(401);

        // Trailing whitespace tampering → the value is compared opaquely → 401.
        $this->postTabby($payload, self::TABBY_SECRET . ' ')->assertStatus(401);

        // Unsigned → 401 (the registered header is absent).
        $this->postTabby($payload, null)->assertStatus(401);

        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function tabby_unresolved_capture_from_a_webhook_keeps_the_row_pending_and_completable(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, 'pay_pending_1');

        // Fresh read: AUTHORIZED and the capture could NOT be confirmed —
        // D1-R requires COMPLETE ABSTENTION: success=false + status=pending.
        $this->mockVerify('tabby', 'pay_pending_1', $this->pendingResult('pay_pending_1', 100.0, 'SAR'));

        $response = $this->postTabby($this->tabbyPayload('pay_pending_1', 'authorized', 100.0, 'SAR'));

        // The response is a terminal-shaped 200-failed ack (Tabby then
        // STOPS retrying this event; a later close webhook completes it).
        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'failed');

        // But the ROW remains pending and completable — never 'failed'.
        $this->assertSame('pending', $txn->fresh()->status, 'D1-R: unresolved capture must stay pending');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($txn->fresh()->idempotency_key);

        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function tabby_rejected_webhook_marks_transaction_failed(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, 'pay_rejected_1');

        // The payload's `rejected` is only a wake-up: the FRESH server read
        // must itself show a terminal provider state before the row fails.
        $this->mockVerify('tabby', 'pay_rejected_1', $this->failedResult('pay_rejected_1', 100.0, 'SAR'));

        $response = $this->postTabby($this->tabbyPayload('pay_rejected_1', 'rejected', 100.0, 'SAR'));

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
    public function tabby_created_webhook_is_ignored_and_state_untouched(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, 'pay_created_1');

        $response = $this->postTabby($this->tabbyPayload('pay_created_1', 'created', 100.0, 'SAR'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'ignored');

        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function tabby_unknown_payment_ref_is_404_so_tabby_retries(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        // DELIBERATE D3 deviation: a real Tabby payment must already exist
        // locally (orders are created BEFORE the payment session), so an
        // unknown id requests a provider RETRY (non-200) — usually a racing
        // commit, and a persistent 404 stays ops-visible.
        $response = $this->postTabby($this->tabbyPayload('pay_no_such_txn', 'authorized', 100.0, 'SAR'));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
        $this->assertDatabaseCount('transactions', 0);
    }

    /** @test */
    public function tabby_webhook_for_a_foreign_gateway_transaction_is_rejected(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, 'pay_foreign_1', gateway: 'myfatoorah');

        $response = $this->postTabby($this->tabbyPayload('pay_foreign_1', 'authorized', 100.0, 'SAR'));

        // Binding check: the referenced transaction belongs to another
        // gateway — reject before any provider call.
        $response->assertStatus(400);
        $response->assertJsonPath('success', false);

        $this->assertSame('pending', $txn->fresh()->status);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function tabby_misconfigured_webhook_secret_is_503(): void
    {
        config(['payment.gateways.tabby.webhook_secret' => '']);

        $payload = $this->tabbyPayload('pay_misconf', 'authorized', 100.0, 'SAR');

        $response = $this->postTabby($payload, self::TABBY_SECRET);

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function tabby_amount_mismatch_blocks_completion(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, 'pay_mismatch_1');

        // The provider-verified amount disagrees with the order snapshot —
        // completion must be refused and the mismatch recorded fail-closed.
        $this->mockVerify('tabby', 'pay_mismatch_1', $this->paidResult('pay_mismatch_1', 999.0, 'SAR'));

        $response = $this->postTabby($this->tabbyPayload('pay_mismatch_1', 'authorized', 100.0, 'SAR'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'failed');

        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNotSame('success', $order->fresh()->payment_status);

        Event::assertDispatchedTimes(PaymentFailed::class, 1);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    // -----------------------------------------------------------------
    // Provider-initiated refund reconciliation (merchant dashboard)
    // -----------------------------------------------------------------

    private function makePaidTxn(Order $order, string $ref, string $currency = 'SAR'): Transaction
    {
        $txn = new Transaction([
            'user_id' => $order->user_id,
            'payment_method' => 'tabby',
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

    /** @test */
    public function tabby_refund_webhook_reconciles_the_ledger_of_a_paid_row(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $order->forceFill([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ])->save();
        $txn = $this->makePaidTxn($order, 'pay_refund_web_1');

        // A `closed` delivery carries the captures[] AND refunds[] — the
        // completion first, then the external refund reconcile.
        $this->mockVerify('tabby', 'pay_refund_web_1', $this->paidResult('pay_refund_web_1', 100.0, 'SAR'));

        $response = $this->postTabby($this->tabbyPayload('pay_refund_web_1', 'closed', 100.0, 'SAR', [
            'refunds' => [
                ['id' => 'refund_ext_1', 'amount' => 100.0],
            ],
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'completed');

        $this->assertSame('paid', $txn->fresh()->status);

        // Full external refund side effects (same as a manual refund).
        $this->assertSame(Order::PAYMENT_STATUS_REFUNDED, $order->fresh()->payment_status);

        // Per-refund dedupe id in the txn ledger.
        $ledger = $txn->fresh()->gateway_response['_refunds'] ?? [];
        $this->assertNotEmpty($ledger);
        $this->assertSame('tabby:refund:' . $txn->id . ':refund_ext_1', $ledger[0]['event_id']);
        $this->assertSame(100.0, (float) $ledger[0]['amount']);
    }

    /** @test */
    public function tabby_refund_replays_never_double_apply_the_refund(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $order->forceFill([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ])->save();
        $txn = $this->makePaidTxn($order, 'pay_refund_web_2');

        $this->mockVerify('tabby', 'pay_refund_web_2', $this->paidResult('pay_refund_web_2', 100.0, 'SAR'));

        $payload = $this->tabbyPayload('pay_refund_web_2', 'closed', 100.0, 'SAR', [
            'refunds' => [
                ['id' => 'refund_ext_2', 'amount' => 100.0],
            ],
        ]);

        $this->postTabby($payload)->assertStatus(200);
        $this->postTabby($payload)->assertStatus(200);

        // Idempotent replay: one ledger entry, refund side effects applied
        // exactly once.
        $ledger = $txn->fresh()->gateway_response['_refunds'] ?? [];
        $this->assertCount(1, $ledger);
        $this->assertSame(Order::PAYMENT_STATUS_REFUNDED, $order->fresh()->payment_status);
        $this->assertStringContainsString('refund_ext_2', json_encode($ledger));
    }

    /** @test */
    public function tabby_refunds_on_a_non_paid_row_are_deferred_not_recorded(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $this->makePendingTxn($order, 'pay_refund_deferred_1');

        // The FRESH server read confirms the terminal failure — the payload
        // never decides it; refunds NEVER reconcile against money the
        // local system has not yet seen captured (row failed, not in the
        // refundable set).
        $this->mockVerify('tabby', 'pay_refund_deferred_1', $this->failedResult('pay_refund_deferred_1', 100.0, 'SAR'));

        $response = $this->postTabby($this->tabbyPayload('pay_refund_deferred_1', 'rejected', 100.0, 'SAR', [
            'refunds' => [
                ['id' => 'refund_ext_never', 'amount' => 100.0],
            ],
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'failed');

        $txn = $order->transactions()->where('gateway_transaction_id', 'pay_refund_deferred_1')->first();
        $this->assertNotSame('paid', $txn->fresh()->status);
        $this->assertArrayNotHasKey('_refunds', (array) $txn->fresh()->gateway_response);
        $this->assertNotSame(Order::PAYMENT_STATUS_REFUNDED, $order->fresh()->payment_status);
    }

    /** @test */
    public function tabby_stale_expired_delivery_after_a_landed_capture_completes_from_the_fresh_read(): void
    {
        // Crash-window recovery (D1-R): the capture landed but the local
        // row is still pending; a DELAYED terminal-failure delivery
        // generated before the capture must never terminally fail money
        // Tabby took — the fresh server read arbitrates BOTH directions.
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $txn = $this->makePendingTxn($order, 'pay_late_capture_1');

        $this->mockVerify('tabby', 'pay_late_capture_1', $this->paidResult('pay_late_capture_1', 100.0, 'SAR'));

        $response = $this->postTabby($this->tabbyPayload('pay_late_capture_1', 'expired', 100.0, 'SAR'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'completed');

        $this->assertSame('paid', $txn->fresh()->status);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNotNull($txn->fresh()->idempotency_key);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function tabby_later_distinct_refunds_reconcile_after_the_first_flips_the_row(): void
    {
        // M2: the first external refund flips the row to
        // partially_refunded — later DISTINCT refund deliveries must still
        // reconcile ('paid'-only gating would drop them forever), while
        // the closed-webhook replay itself stays a cheap dedupe.
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $order = $this->makeOrder(100.0, 'SAR');
        $order->forceFill([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ])->save();
        $txn = $this->makePaidTxn($order, 'pay_refund_chain_1');

        // First delivery completes via the fresh read and reconciles
        // refund A (40.00).
        $this->mockVerify('tabby', 'pay_refund_chain_1', $this->paidResult('pay_refund_chain_1', 100.0, 'SAR'));

        $first = $this->postTabby($this->tabbyPayload('pay_refund_chain_1', 'closed', 100.0, 'SAR', [
            'refunds' => [
                ['id' => 'refund_chain_a', 'amount' => 40.0],
            ],
        ]));
        $first->assertStatus(200);

        $this->assertSame('partially_refunded', $txn->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $order->fresh()->payment_status);

        // Second delivery: the closed webhook is a dedupe replay (no fresh
        // read — only one mockVerify), and refund B (60.00) must still
        // reconcile against the partially_refunded row.
        $second = $this->postTabby($this->tabbyPayload('pay_refund_chain_1', 'closed', 100.0, 'SAR', [
            'refunds' => [
                ['id' => 'refund_chain_b', 'amount' => 60.0],
            ],
        ]));
        $second->assertStatus(200);

        $ledger = $txn->fresh()->gateway_response['_refunds'] ?? [];
        $this->assertCount(2, $ledger);
        $this->assertSame('tabby:refund:' . $txn->id . ':refund_chain_a', $ledger[0]['event_id']);
        $this->assertSame('tabby:refund:' . $txn->id . ':refund_chain_b', $ledger[1]['event_id']);
        $this->assertSame('refunded', $txn->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_REFUNDED, $order->fresh()->payment_status);
    }
}

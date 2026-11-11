<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\GatewayResult;
use App\Services\Payment\Contracts\CapturingGatewayContract;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\Contracts\ReadonlyPaymentProbeContract;
use App\Services\Payment\PaymentCapabilityRegistry;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PayPalWebhookVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Payment Attempt architecture (PM-1/PM-4/PM-5/PM-6/PM-7/W-1/T-03/SC-1).
 *
 * Financial-safety invariants under test (constraint E):
 *  - read-only probes never move money (E.8)
 *  - one logical capture per attempt, lease-arbitrated (E.1/E.7)
 *  - Unknown never becomes Failed by elapsed time (E.3)
 *  - unresolved payable sessions block replacement (E.4)
 *  - webhooks durably persisted before processing (E.5), replay-safe (E.6)
 *  - completion exclusively via completeLocked (E.10)
 */
class AttemptLifecycleArchitectureTest extends TestCase
{
    use CreatesTestTables;
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    private const PAYPAL_URL = '/api/v1/general/checkout/webhooks/paypal';
    private const TABBY_URL = '/api/v1/general/checkout/webhooks/tabby';

    protected function setUp(): void
    {
        parent::setUp();

        config(['payment.gateways.paypal.mode' => 'sandbox']);
        config(['payment.gateways.paypal.webhook_id' => 'WH-TEST-123']);
        config(['payment.gateways.paypal.account' => 'default']);
        // Completion runs the invoice snapshot validator, which reads the
        // myfatoorah supported-currency list as the checkout authority.
        config(['payment.gateways.myfatoorah.supported_currencies' => ['USD', 'EGP', 'EUR', 'SAR', 'AED', 'KWD']]);
        config(['payment.gateways.tabby.webhook_secret' => 'tabby-secret']);
        config(['payment.gateways.tabby.webhook_header' => 'X-Test-Auth']);
        config(['payment.gateways.tabby.account' => 'default']);

        // Completion (changeOrderStatus → currency resolution) needs the
        // currency catalog seeded; the attempt tests run USD end-to-end.
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], [
            'name' => json_encode(['en' => 'US Dollar']),
            'decimal_places' => 2,
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Idempotent bootstrap: heals/creates missing tables and seeds the
        // order-status flow catalog (order_statuses/order_flows) on every
        // run, regardless of whether migrations previously ran.
        $this->createAllTestTables();
        $this->seedOrderStatusFlowTables();
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Test double: probe/capture fake with call recording.
    // -----------------------------------------------------------------
    private function makeGatewayFake(string $initialProviderStatus = 'APPROVED', string $providerId = 'PP-ORDER-1'): object
    {
        return new class($initialProviderStatus, $providerId) implements PaymentGatewayContract, ReadonlyPaymentProbeContract, CapturingGatewayContract
        {
            public int $probeCalls = 0;
            public array $captureCalls = [];
            public bool $captured = false;
            public string $probeProviderStatus;
            public string $providerId;

            public function __construct(string $probeProviderStatus, string $providerId)
            {
                $this->probeProviderStatus = $probeProviderStatus;
                $this->providerId = $providerId;
            }

            private function probe(): GatewayResult
            {
                $this->probeCalls++;

                $raw = ['_probe_provider_status' => $this->captured ? 'COMPLETED' : $this->probeProviderStatus];

                if ($this->captured) {
                    return new GatewayResult(
                        success: true,
                        gatewayTransactionId: $this->providerId,
                        amount: 100.0,
                        currency: 'USD',
                        status: 'paid',
                        rawResponse: $raw,
                    );
                }

                if ($this->probeProviderStatus === 'VOIDED') {
                    return new GatewayResult(
                        success: false,
                        gatewayTransactionId: $this->providerId,
                        status: 'failed',
                        errorMessage: 'Payment not completed',
                        rawResponse: $raw,
                    );
                }

                return new GatewayResult(
                    success: true,
                    gatewayTransactionId: $this->providerId,
                    status: 'pending',
                    rawResponse: $raw,
                );
            }

            public function probePaymentStatus(string $gatewayTransactionId): GatewayResult
            {
                return $this->probe();
            }

            public function capturePayment(string $gatewayTransactionId): GatewayResult
            {
                $this->captureCalls[] = $gatewayTransactionId;
                $this->captured = true;

                return new GatewayResult(
                    success: true,
                    gatewayTransactionId: $gatewayTransactionId,
                    amount: 100.0,
                    currency: 'USD',
                    status: 'paid',
                    rawResponse: ['_probe_provider_status' => 'COMPLETED'],
                );
            }

            public function verifyPayment(string $gatewayTransactionId): GatewayResult
            {
                throw new \RuntimeException('verifyPayment must never be called on infrastructure paths');
            }

            public function createInvoice($order, $amount, $callbackUrl, $errorUrl, array $metadata = []): GatewayResult
            {
                return new GatewayResult(success: false);
            }

            public function refund($order, $amount, ?string $reason = null): GatewayResult
            {
                return new GatewayResult(success: false);
            }

            public function name(): string
            {
                return 'paypal';
            }

            public function code(): string
            {
                return 'paypal';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function supportsCurrency(string $currencyCode): bool
            {
                return true;
            }
        };
    }

    private function bindFactory(object $gateway, string $name = 'paypal'): void
    {
        $factory = \Mockery::mock(PaymentGatewayFactory::class);
        $factory->shouldReceive('make')->with($name)->andReturn($gateway);
        $this->instance(PaymentGatewayFactory::class, $factory);
    }

    private function makeOrder(array $overrides = []): Order
    {
        $user = User::factory()->create();

        $flowId = DB::table('order_flows')->where('is_default', 1)->value('id');
        $pendingStatusId = DB::table('order_statuses')->where('code', 'pending')->value('id');

        return Order::create(array_merge([
            'user_id' => $user->id,
            'order_number' => 'ORD-' . uniqid(),
            'name' => 'Attempt Customer',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'address' => '1 Attempt Street',
            'status' => 'pending',
            'payment_status' => 'payment-pending',
            'payment_method' => 'online',
            'payment_gateway' => 'paypal',
            'total_price' => 100,
            'price' => 100,
            'currency_code' => 'USD',
            'inventory_state' => 'active',
            'flow_id' => $flowId,
            'current_status_id' => $pendingStatusId,
        ], $overrides));
    }

    private function makeAttemptRow(Order $order, array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'order_id' => $order->id,
            'status' => 'pending',
            'payment_method' => 'paypal',
            'amount' => 100,
            'currency' => 'USD',
            'attempt_state' => 'session_created',
            'dispatch_reference' => 'REF-TEST-1',
            'gateway_transaction_id' => 'PP-ORDER-1',
            'user_id' => $order->user_id,
        ], $overrides));
    }

    private function bindPayPalVerifier(): void
    {
        $verifier = \Mockery::mock(PayPalWebhookVerifier::class);
        $verifier->shouldReceive('verify')->andReturn(true);
        $this->instance(PayPalWebhookVerifier::class, $verifier);
    }

    private function approvedPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 'EVT-1',
            'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => [
                'id' => 'PP-ORDER-1',
                'custom_id' => 'REF-TEST-1',
                'amount' => ['value' => '100.00', 'currency_code' => 'USD'],
                'supplementary_data' => ['related_ids' => ['order_id' => 'PP-ORDER-1']],
            ],
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // PM-5: probes never move money (E.8).
    // -----------------------------------------------------------------

    /** @test */
    public function paypal_probe_on_approved_order_never_captures(): void
    {
        $gateway = $this->makeGatewayFake('APPROVED');

        $result = $gateway->probePaymentStatus('PP-ORDER-1');

        $this->assertSame([], $gateway->captureCalls, 'PM-5 E.8: probe must never capture');
        $this->assertTrue($result->success);
        $this->assertSame('pending', $result->status);
        $this->assertSame('APPROVED', $result->rawResponse['_probe_provider_status']);
    }

    // -----------------------------------------------------------------
    // PM-7: APPROVED webhook → exactly one logical capture (E.1/E.7/E.10).
    // -----------------------------------------------------------------

    /** @test */
    public function approved_webhook_captures_once_and_completes_via_completeLocked(): void
    {
        $gateway = $this->makeGatewayFake('APPROVED');
        $this->bindFactory($gateway);
        $this->bindPayPalVerifier();

        $order = $this->makeOrder();
        $txn = $this->makeAttemptRow($order);

        $response = $this->postJson(self::PAYPAL_URL, $this->approvedPayload(), $this->paypalHeaders());

        $response->assertStatus(200);
        $this->assertCount(1, $gateway->captureCalls, 'E.1: exactly one logical capture');
        $this->assertSame('succeeded', Transaction::query()->first()->capture_state);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', Transaction::query()->first()->status);
        $this->assertDatabaseHas('payment_webhook_events', ['provider' => 'paypal', 'status' => 'processed']);
    }

    /** @test */
    public function duplicate_approved_webhook_never_recaptures(): void
    {
        $gateway = $this->makeGatewayFake('APPROVED');
        $this->bindFactory($gateway);
        $this->bindPayPalVerifier();

        $order = $this->makeOrder();
        $this->makeAttemptRow($order);

        $this->postJson(self::PAYPAL_URL, $this->approvedPayload(), $this->paypalHeaders())->assertStatus(200);
        $this->postJson(self::PAYPAL_URL, $this->approvedPayload(), $this->paypalHeaders())->assertStatus(200);

        $this->assertCount(1, $gateway->captureCalls, 'E.1: duplicate event must not re-capture');
        $this->assertDatabaseCount('payment_webhook_events', 1);
    }

    /** @test */
    public function capture_race_between_webhook_and_worker_yields_one_capture(): void
    {
        $gateway = $this->makeGatewayFake('APPROVED');
        $this->bindFactory($gateway);
        $this->bindPayPalVerifier();

        $order = $this->makeOrder();
        $txn = $this->makeAttemptRow($order);

        // Worker claims the capture first (lease ownership).
        $lifecycle = app(\App\Services\Payment\PaymentAttemptLifecycleService::class);
        $lifecycle->requestCapture($txn);
        $this->assertTrue($lifecycle->claimCapture($txn));

        // Webhook arrives while the worker owns the capture.
        $this->postJson(self::PAYPAL_URL, $this->approvedPayload(), $this->paypalHeaders())->assertStatus(200);

        $this->assertCount(0, $gateway->captureCalls, 'E.7: webhook must not bypass capture coordination');
        $this->assertSame('processing', $txn->fresh()->capture_state);
    }

    // -----------------------------------------------------------------
    // PM-6: webhook-before-persistence correlation (claimed adoption).
    // -----------------------------------------------------------------

    /** @test */
    public function webhook_adopts_claimed_attempt_via_dispatch_reference(): void
    {
        $gateway = $this->makeGatewayFake('APPROVED');
        $this->bindFactory($gateway);
        $this->bindPayPalVerifier();

        $order = $this->makeOrder();

        // Claimed row: NO provider id yet — only the dispatch reference.
        $txn = $this->makeAttemptRow($order, [
            'attempt_state' => 'claimed',
            'gateway_transaction_id' => null,
        ]);

        $this->postJson(self::PAYPAL_URL, $this->approvedPayload(), $this->paypalHeaders())->assertStatus(200);

        $txn->refresh();

        $this->assertSame('session_created', $txn->attempt_state, 'PM-6 adoption');
        $this->assertSame('PP-ORDER-1', $txn->gateway_transaction_id);
        $this->assertSame('paid', $txn->status);
        $this->assertSame('completed', $order->fresh()->status);
    }

    /** @test */
    public function tabby_webhook_correlates_claimed_row_via_reference_echo(): void
    {
        $gateway = $this->makeGatewayFake('APPROVED', 'TB-PAY-1');
        $this->bindFactory($gateway, 'tabby');
        $this->bindPayPalVerifier();

        $order = $this->makeOrder();
        Transaction::create([
            'order_id' => $order->id,
            'status' => 'pending',
            'payment_method' => 'tabby',
            'amount' => 100,
            'currency' => 'USD',
            'attempt_state' => 'claimed',
            'dispatch_reference' => 'REF-TABBY-1',
            'gateway_transaction_id' => null,
            'user_id' => $order->user_id,
        ]);

        // Tabby fresh-read statuses arbitrate via the adapter probe; the
        // payment object echoes order.reference_id (PM-6 carrier).
        $payload = [
            'id' => 'TB-PAY-1',
            'status' => 'authorized',
            'order' => ['reference_id' => 'REF-TABBY-1'],
            'amount' => 100.0,
            'currency' => 'USD',
        ];

        $this->postJson(self::TABBY_URL, $payload, ['X-Test-Auth' => 'tabby-secret'])->assertStatus(200);

        $txn = Transaction::query()->where('dispatch_reference', 'REF-TABBY-1')->first();
        $this->assertSame('session_created', $txn->attempt_state);
        $this->assertSame('TB-PAY-1', $txn->gateway_transaction_id);
    }

    /** @test */
    public function tap_correlation_is_fail_closed_until_sandbox_verified(): void
    {
        $this->assertFalse(PaymentCapabilityRegistry::correlationActive('tap'), 'Constraint D: Tap disabled');
        $this->assertFalse(PaymentCapabilityRegistry::correlationActive('myfatoorah'), 'Constraint D: MyFatoorah ops-only');
        $this->assertTrue(PaymentCapabilityRegistry::correlationActive('stripe'));
        $this->assertTrue(PaymentCapabilityRegistry::correlationActive('paypal'));
    }

    // -----------------------------------------------------------------
    // T-03 / E.3: Unknown never becomes Failed by elapsed time.
    // -----------------------------------------------------------------

    /** @test */
    public function inconclusive_probe_keeps_attempt_unknown(): void
    {
        $gateway = new class implements PaymentGatewayContract, ReadonlyPaymentProbeContract, CapturingGatewayContract
        {
            public function probePaymentStatus(string $gatewayTransactionId): GatewayResult
            {
                return new GatewayResult(
                    success: false,
                    gatewayTransactionId: $gatewayTransactionId,
                    status: 'pending',
                    errorMessage: 'Payment gateway error',
                );
            }

            public function capturePayment(string $gatewayTransactionId): GatewayResult
            {
                throw new \RuntimeException('must not capture');
            }

            public function verifyPayment(string $gatewayTransactionId): GatewayResult
            {
                throw new \RuntimeException('must not verify');
            }

            public function createInvoice($order, $amount, $callbackUrl, $errorUrl, array $metadata = []): GatewayResult
            {
                return new GatewayResult(success: false);
            }

            public function refund($order, $amount, ?string $reason = null): GatewayResult
            {
                return new GatewayResult(success: false);
            }

            public function name(): string
            {
                return 'paypal';
            }

            public function code(): string
            {
                return 'paypal';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function supportsCurrency(string $currencyCode): bool
            {
                return true;
            }
        };

        $this->bindFactory($gateway);
        $this->bindPayPalVerifier();

        $order = $this->makeOrder();
        $txn = $this->makeAttemptRow($order);

        $this->postJson(self::PAYPAL_URL, [
            'id' => 'EVT-X',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'PP-ORDER-1', 'supplementary_data' => ['related_ids' => ['order_id' => 'PP-ORDER-1']]],
        ], $this->paypalHeaders())->assertStatus(200);

        $txn->refresh();

        $this->assertSame('pending', $txn->status, 'E.3: inconclusive probe must not fail the row');
    }

    /** PayPal delivery headers (verified off-provider; empty ⇒ 400). */
    private function paypalHeaders(): array
    {
        return [
            'Paypal-Transmission-Id' => 'T-1',
            'Paypal-Transmission-Time' => '2026-11-10T00:00:00Z',
            'Paypal-Transmission-Sig' => 'sig',
            'Paypal-Auth-Algo' => 'SHA256withRSA',
            'Paypal-Cert-Url' => 'https://example.com/cert',
        ];
    }

    // -----------------------------------------------------------------
    // T-03 / R-POL-1: Reaper skips unresolved attempts; cancel gated (E.4).
    // -----------------------------------------------------------------

    /** @test */
    public function reaper_skips_orders_with_unresolved_attempts(): void
    {
        $order = $this->makeOrder();
        $this->makeAttemptRow($order);

        Order::query()->whereKey($order->id)->update([
            'reservation_expires_at' => now()->subHour(),
        ]);

        $this->artisan('orders:cancel-unpaid');

        $this->assertSame('pending', $order->fresh()->status, 'R-POL-1: unresolved attempt blocks reaper');
    }

    /** @test */
    public function reaper_cancels_after_provider_confirmed_failure(): void
    {
        $order = $this->makeOrder();
        $this->makeAttemptRow($order, ['status' => 'failed']);

        Order::query()->whereKey($order->id)->update([
            'reservation_expires_at' => now()->subHour(),
        ]);

        $this->artisan('orders:cancel-unpaid');

        $this->assertSame('cancelled', $order->fresh()->status, 'provider-confirmed failure releases the block');
    }

    /** @test */
    public function cancellation_is_rejected_while_attempt_unresolved(): void
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-CANCEL-' . uniqid(),
            'name' => 'Cancel Customer',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'address' => '1 Cancel Street',
            'status' => 'pending',
            'payment_status' => 'payment-pending',
            'payment_method' => 'online',
            'payment_gateway' => 'paypal',
            'total_price' => 100,
            'price' => 100,
            'currency_code' => 'USD',
        ]);
        $this->makeAttemptRow($order);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/general/orders/' . $order->id . '/cancel')
            ->assertStatus(409);
    }

    // -----------------------------------------------------------------
    // PM-7: redirect is UX-only for lifecycle attempts (E.9).
    // -----------------------------------------------------------------

    /** @test */
    public function callback_for_lifecycle_attempt_never_captures_or_completes(): void
    {
        $gateway = $this->makeGatewayFake('APPROVED');
        $this->bindFactory($gateway);
        $this->bindPayPalVerifier();

        $order = $this->makeOrder();
        $this->makeAttemptRow($order);

        $this->get('/api/v1/general/checkout/callback?paymentId=PP-ORDER-1')->assertStatus(200);

        $this->assertCount(0, $gateway->captureCalls, 'E.9: redirect must never capture');
        $this->assertSame('pending', $order->fresh()->status, 'E.9: redirect must never complete');
    }

    // -----------------------------------------------------------------
    // PM-1: dispatch reference uniqueness + unresolved gate (E.4).
    // -----------------------------------------------------------------

    /** @test */
    public function dispatch_reference_is_unique_across_attempts(): void
    {
        $order = $this->makeOrder();
        $this->makeAttemptRow($order, ['dispatch_reference' => 'REF-DUP']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        Transaction::create([
            'order_id' => $order->id,
            'status' => 'pending',
            'payment_method' => 'paypal',
            'attempt_state' => 'claimed',
            'dispatch_reference' => 'REF-DUP',
            'gateway_transaction_id' => null,
            'user_id' => $order->user_id,
            'amount' => 100,
            'currency' => 'USD',
        ]);
    }

    /** @test */
    public function unresolved_attempt_blocks_new_attempt_for_the_order(): void
    {
        $order = $this->makeOrder();
        $this->makeAttemptRow($order);

        $lifecycle = app(\App\Services\Payment\PaymentAttemptLifecycleService::class);
        $this->assertTrue($lifecycle->hasUnresolvedAttempt($order->id), 'SC-1/E.4');

        // Locally-marked failed WITH provider evidence releases the block.
        $txn = Transaction::query()->first();
        $lifecycle->markFailedWithProviderEvidence($txn, 'probe:paypal', 'VOIDED');
        $this->assertFalse($lifecycle->hasUnresolvedAttempt($order->id));
    }
}

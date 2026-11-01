<?php

namespace Tests\Unit;

use App\DTOs\GatewayResult;
use App\Services\Gateway\TabbyGateway;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\Order;
use Tests\TestCase;

/**
 * TabbyGateway adapter unit tests — DB-free (MySQL unreachable in this
 * environment; see docs/audits/tabby-payment-integration-audit.md).
 *
 * Every provider interaction runs through Http::fake() +
 * Http::preventStrayRequests() — no test below ever touches the network,
 * and any code path that reaches Tabby without a matching fake fails
 * loudly. The order fixture is a non-persisted model:
 * PaymentCurrencyResolver (catalog_currency_code first) and
 * CustomerContactResolver read attributes only on these guarded paths.
 * Refund DB paths (transactions() relation) are covered by the catalogued
 * feature test instead.
 *
 * The behavioral contracts tested here are the capture-first lifecycle
 * guarantees (D1-R):
 *   - success == Tabby-verified CLOSED + non-empty captures[] ONLY;
 *   - AUTHORIZED without a confirmed capture  => NON-TERMINAL pending
 *     (success=false, status='pending') after bounded same-reference_id
 *     retries — never 'failed', never completable without the provider truth;
 *   - ambiguous reads (transport failures, unknown statuses, CREATED) =>
 *     pending, never terminal.
 */
class TabbyGatewayTest extends TestCase
{
    private const PAYMENT_ID = '01a04380-64b8-8432-a20f-904a48584390';

    private const CONFIG = [
        'class' => TabbyGateway::class,
        'enabled' => true,
        'secret_key' => 'sk_test_unit_secret',
        'base_url' => 'https://api.tabby.ai/api/v2',
        'merchant_code' => 'tabby_merchant_unit',
        'webhook_header' => 'x-tabby-signature',
        'webhook_secret' => 'unit_webhook_shared_secret',
        'supported_currencies' => ['AED', 'SAR'],
        'capture_attempts' => 2,
        'capture_retry_delay_ms' => 0,
        'methods' => ['online'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['payment.gateways.tabby' => self::CONFIG]);

        Http::preventStrayRequests();
    }

    private function gateway(): TabbyGateway
    {
        return app(TabbyGateway::class);
    }

    private function order(string $currency = 'AED', float $total = 100.0): Order
    {
        $order = new Order();
        $order->forceFill([
            'id' => 5,
            'order_number' => 'ORD-5',
            'name' => 'Test Customer',
            'user_phone' => '966500000001',
            'address' => ['city' => 'Dubai', 'street' => 'Sheikh Zayed Rd', 'zip' => '00000'],
            'total_price' => $total,
            'catalog_currency_code' => $currency,
            'created_at' => now(),
        ]);

        return $order;
    }

    private function sessionResponse(array $overrides = []): array
    {
        return array_merge([
            'id' => 'e5f9d6c8-0000-0000-0000-000000000000',
            'status' => 'created',
            'payment' => [
                'id' => self::PAYMENT_ID,
                'status' => 'CREATED',
                'amount' => '100.00',
                'currency' => 'AED',
                'buyer' => [
                    'name' => 'Leaked',
                    'email' => 'leaked@example.com',
                    'phone' => '966500000001',
                ],
            ],
            'configuration' => [
                'available_products' => [
                    'installments' => [
                        [
                            'web_url' => 'https://checkout.tabby.ai/?sessionId=e5f9&product=installments',
                        ],
                    ],
                ],
                'products' => [
                    'installments' => ['type' => 'installments', 'is_available' => true, 'rejection_reason' => null],
                ],
            ],
        ], $overrides);
    }

    private function paymentResponse(array $overrides = []): array
    {
        return array_merge([
            'id' => self::PAYMENT_ID,
            'status' => 'AUTHORIZED',
            'amount' => '100.00',
            'currency' => 'AED',
            'buyer' => [
                'name' => 'Leaked',
                'email' => 'leaked@example.com',
                'phone' => '966500000001',
            ],
            'order' => ['reference_id' => '5'],
            'captures' => [],
            'refunds' => [],
        ], $overrides);
    }

    /** @test */
    public function it_implements_the_gateway_contract()
    {
        $this->assertInstanceOf(PaymentGatewayContract::class, $this->gateway());
        $this->assertSame('tabby', $this->gateway()->code());
        $this->assertSame('tabby', $this->gateway()->name());
        $this->assertTrue($this->gateway()->isConfigured());
        $this->assertTrue($this->gateway()->supportsCurrency('AED'));
        $this->assertTrue($this->gateway()->supportsCurrency('SAR'));
        $this->assertFalse($this->gateway()->supportsCurrency('USD'));
        $this->assertFalse($this->gateway()->supportsCurrency('KWD'));
    }

    /** @test */
    public function it_is_not_configured_without_a_secret_key()
    {
        config(['payment.gateways.tabby.secret_key' => null]);

        $this->assertFalse($this->gateway()->isConfigured());
    }

    /** @test */
    public function it_creates_a_session_per_the_documented_contract()
    {
        Http::fake([
            'api.tabby.ai/api/v2/checkout' => Http::response($this->sessionResponse(), 200),
        ]);

        $result = $this->gateway()->createInvoice(
            $this->order(),
            100.0,
            'https://app.test/callback',
            'https://app.test/error',
        );

        $this->assertInstanceOf(GatewayResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame('https://checkout.tabby.ai/?sessionId=e5f9&product=installments', $result->redirectUrl);
        $this->assertSame(self::PAYMENT_ID, $result->gatewayTransactionId);
        $this->assertSame('pending', $result->status);
        $this->assertSame('AED', $result->currency);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.tabby.ai/api/v2/checkout') {
                return false;
            }

            if (!str_contains((string) $request->header('Authorization')[0], 'Bearer sk_test_unit_secret')) {
                return false;
            }

            $body = $request->data();

            // Top-level siblings of `payment` (docs session-payload-model):
            // lang + merchant_code + merchant_urls are NOT nested inside it.
            $topLevel = ['payment', 'lang', 'merchant_code', 'merchant_urls'];

            if (array_keys($body) !== $topLevel) {
                return false;
            }

            $payment = $body['payment'];

            return $payment['amount'] === '100.00'
                && $payment['currency'] === 'AED'
                && $payment['buyer']['name'] === 'Test Customer'
                && $payment['buyer']['email'] !== ''
                && $payment['buyer']['phone'] === '966500000001'
                && $payment['shipping_address']['city'] === 'Dubai'
                && $payment['shipping_address']['address'] === 'Sheikh Zayed Rd'
                && $payment['shipping_address']['zip'] === '00000'
                && $payment['order']['reference_id'] === '5'
                && $payment['order']['items'][0]['title'] === 'Order #ORD-5'
                && $payment['order']['items'][0]['quantity'] === 1
                && $payment['order']['items'][0]['unit_price'] === '100.00'
                && isset($payment['order']['items'][0]['category'], $payment['buyer_history']['registered_since'])
                && $payment['meta']['order_id'] === '5'
                // Session `lang` contract (docs session-payload-model) is the
                // enum ar|en — this environment boots with the app's `ar`
                // locale, so membership is the correct assertion, never 'en'.
                && in_array($body['lang'], ['ar', 'en'], true)
                && $body['merchant_code'] === 'tabby_merchant_unit'
                && $body['merchant_urls']['success'] === 'https://app.test/callback'
                && $body['merchant_urls']['cancel'] === 'https://app.test/callback'
                && $body['merchant_urls']['failure'] === 'https://app.test/callback';
        });
    }

    /** @test */
    public function it_never_hardcodes_the_payment_currency()
    {
        Http::fake([
            'api.tabby.ai/api/v2/checkout' => Http::response($this->sessionResponse([
                'payment' => [
                    'id' => self::PAYMENT_ID,
                    'status' => 'CREATED',
                    'amount' => '85.00',
                    'currency' => 'SAR',
                    'buyer' => ['name' => 'Leaked', 'email' => 'leaked@example.com'],
                ],
            ]), 200),
        ]);

        $result = $this->gateway()->createInvoice(
            $this->order(currency: 'SAR', total: 85.0),
            85.0,
            'https://app.test/callback',
            'https://app.test/error',
        );

        $this->assertTrue($result->success);
        $this->assertSame('SAR', $result->currency);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['payment']['currency'] === 'SAR' && $body['payment']['amount'] === '85.00';
        });
    }

    /** @test */
    public function it_fails_closed_on_an_unsupported_order_currency()
    {
        $result = $this->gateway()->createInvoice(
            $this->order(currency: 'USD'),
            100.0,
            'https://app.test/callback',
            'https://app.test/error',
        );

        $this->assertFalse($result->success);
        $this->assertSame(
            __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => 'USD']),
            $result->errorMessage,
        );
        Http::assertNothingSent();
    }

    /** @test */
    public function it_fails_closed_on_a_non_positive_amount()
    {
        foreach ([0.0, -5.0] as $amount) {
            $result = $this->gateway()->createInvoice(
                $this->order(),
                $amount,
                'https://app.test/callback',
                'https://app.test/error',
            );

            $this->assertFalse($result->success);
            $this->assertSame('Invalid payment amount', $result->errorMessage);
        }

        Http::assertNothingSent();
    }

    /** @test */
    public function it_fails_closed_when_the_provider_rejects_the_session()
    {
        // Tabby's documented error envelope carries its text in `error`
        // (string) or `errors[]` (docs response-codes) — there is no
        // `message` field. errorMessageFrom therefore echoes that text (the
        // Tabby analog of TapGateway extracting `message`), bounded /
        // strip_tags / sk_-redacted; a NON-string error shape never leaks
        // and falls back to "<fallback> (HTTP <status>)".
        //
        // NOTE: Http::fake() URL-map stubs MERGE (first-non-null wins —
        // Laravel Factory source), so two responses for the same endpoint
        // must be delivered via Http::sequence().
        Http::fake([
            'api.tabby.ai/api/v2/checkout' => Http::sequence()
                ->push([
                    'status' => 400,
                    'errorType' => 'ValidationError',
                    'errors' => ['amount is invalid'],
                ], 400)
                ->push([
                    'status' => 400,
                    'errorType' => 'ValidationError',
                    'error' => ['field' => 'amount'], // non-string shape
                ], 400),
        ]);

        $result = $this->gateway()->createInvoice(
            $this->order(),
            100.0,
            'https://app.test/callback',
            'https://app.test/error',
        );

        $this->assertFalse($result->success);
        $this->assertSame('amount is invalid', $result->errorMessage);
        $this->assertNull($result->rawResponse);

        // Non-string `error` shapes are never echoed — the fallback
        // carries the HTTP status instead.
        $result = $this->gateway()->createInvoice(
            $this->order(),
            100.0,
            'https://app.test/callback',
            'https://app.test/error',
        );

        $this->assertFalse($result->success);
        $this->assertSame('Session request failed (HTTP 400)', $result->errorMessage);
    }

    /** @test */
    public function it_fails_closed_on_a_transport_failure()
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timeout');
        });

        $result = $this->gateway()->createInvoice(
            $this->order(),
            100.0,
            'https://app.test/callback',
            'https://app.test/error',
        );

        $this->assertFalse($result->success);
        $this->assertSame('Payment gateway error', $result->errorMessage);
    }

    /** @test */
    public function it_fails_closed_when_the_session_lacks_a_payment_page_url()
    {
        // Rejected session (docs): available_products comes back EMPTY —
        // no web_url to redirect to. Never a success without a redirect.
        Http::fake([
            'api.tabby.ai/api/v2/checkout' => Http::response($this->sessionResponse([
                'status' => 'rejected',
                'configuration' => [
                    'available_products' => [],
                    'products' => [
                        'installments' => [
                            'type' => 'installments',
                            'is_available' => false,
                            'rejection_reason' => 'order_amount_too_high',
                        ],
                    ],
                ],
            ]), 200),
        ]);

        $result = $this->gateway()->createInvoice(
            $this->order(),
            10000001.0,
            'https://app.test/callback',
            'https://app.test/error',
        );

        $this->assertFalse($result->success);
        $this->assertSame(
            __('message.ERROR.PAYMENT_PROVIDER_DECLINED'),
            $result->errorMessage,
        );
        $this->assertSame('order_amount_too_high', $result->rawResponse['rejection_reason']);
    }

    /** @test */
    public function it_strips_buyer_pii_from_the_stored_session_response()
    {
        Http::fake([
            'api.tabby.ai/api/v2/checkout' => Http::response($this->sessionResponse(), 200),
        ]);

        $result = $this->gateway()->createInvoice(
            $this->order(),
            100.0,
            'https://app.test/callback',
            'https://app.test/error',
        );

        $raw = $result->rawResponse ?? [];

        $this->assertSame(self::PAYMENT_ID, $raw['payment_id']);
        $this->assertArrayNotHasKey('payment', $raw);
        $this->assertStringNotContainsString('Leaked', json_encode($raw));
        $this->assertStringNotContainsString('leaked@example.com', json_encode($raw));
        $this->assertStringNotContainsString('checkout.tabby.ai', json_encode($raw));
    }

    /** @test */
    public function it_completes_paid_after_a_confirmed_full_capture()
    {
        // AUTHORIZED (fresh read) → capture 2xx → fresh read CLOSED + captures.
        Http::fake([
            'api.tabby.ai/api/v2/payments/'.self::PAYMENT_ID => Http::sequence()
                ->push($this->paymentResponse(), 200) // initial verification
                ->push($this->paymentResponse(overrides: [ // post-capture arbitration
                    'status' => 'CLOSED',
                    'captures' => [['id' => 'cap_1', 'amount' => '100.00', 'created_at' => '2026-01-20T09:20:00Z', 'reference_id' => 'capture-'.self::PAYMENT_ID]],
                ]), 200),
            'api.tabby.ai/api/v2/payments/*/captures' => Http::response(['id' => 'cap_1'], 200),
        ]);

        $result = $this->gateway()->verifyPayment(self::PAYMENT_ID);

        $this->assertTrue($result->success);
        $this->assertSame('paid', $result->status);
        $this->assertSame(self::PAYMENT_ID, $result->gatewayTransactionId);
        $this->assertSame(100.0, $result->amount);
        $this->assertSame('AED', $result->currency);
        $this->assertSame('captured', $result->rawResponse['_capture']['outcome']);

        // Capture request carries the idempotent reference_id + full amount.
        Http::assertSent(function ($request) {
            if (!str_ends_with((string) $request->url(), '/payments/'.self::PAYMENT_ID.'/captures')) {
                return false;
            }

            $body = $request->data();

            return $body['amount'] === '100.00'
                && $body['reference_id'] === 'capture-'.self::PAYMENT_ID;
        });
    }

    /** @test */
    public function it_treats_the_benign_already_closed_capture_as_paid()
    {
        // Docs edge case: a first capture landed (same reference_id), the
        // retry gets 400 "already closed" — fresh read arbitrates CLOSED
        // + captures => paid, never a failure.
        Http::fake([
            'api.tabby.ai/api/v2/payments/'.self::PAYMENT_ID => Http::sequence()
                ->push($this->paymentResponse(), 200)
                ->push($this->paymentResponse(overrides: [
                    'status' => 'CLOSED',
                    'captures' => [['id' => 'cap_from_prior_attempt', 'amount' => '100.00']],
                ]), 200),
            'api.tabby.ai/api/v2/payments/*/captures' => Http::response([
                'status' => 400,
                'error' => 'Could not capture payment with reference_id capture-01a04380...: already closed',
            ], 400),
        ]);

        $result = $this->gateway()->verifyPayment(self::PAYMENT_ID);

        $this->assertTrue($result->success, 'A landed capture behind a benign 400 must complete');
        $this->assertSame('paid', $result->status);
    }

    /** @test */
    public function it_retries_locked_captures_with_the_same_reference_id()
    {
        // 409 "payment is locked" twice → third capture lands → closed.
        Http::fake([
            'api.tabby.ai/api/v2/payments/'.self::PAYMENT_ID => Http::sequence()
                ->push($this->paymentResponse(), 200)
                ->push($this->paymentResponse(overrides: [
                    'status' => 'CLOSED',
                    'captures' => [['id' => 'cap_1', 'amount' => '100.00']],
                ]), 200),
            'api.tabby.ai/api/v2/payments/*/captures' => Http::sequence()
                ->push(['errorType' => 'Conflict', 'error' => 'payment is locked'], 409)
                ->push(['errorType' => 'Conflict', 'error' => 'payment is locked'], 409)
                ->push(['id' => 'cap_1'], 200),
        ]);

        $result = $this->gateway()->verifyPayment(self::PAYMENT_ID);

        $this->assertTrue($result->success);
        $this->assertSame('paid', $result->status);

        $captureRequests = [];
        Http::assertSent(function ($request) use (&$captureRequests) {
            if (!str_ends_with((string) $request->url(), '/payments/'.self::PAYMENT_ID.'/captures')) {
                return false;
            }

            $captureRequests[] = $request->data();

            return true;
        });

        $this->assertCount(3, $captureRequests, 'capture_attempts=2 => up to 3 attempts');

        foreach ($captureRequests as $body) {
            // Idempotency: every retry shares THE SAME reference_id.
            $this->assertSame('100.00', $body['amount']);
            $this->assertSame('capture-'.self::PAYMENT_ID, $body['reference_id']);
        }
    }

    /** @test */
    public function it_stays_pending_when_the_capture_budget_is_exhausted()
    {
        // Every attempt 409, budget exhausted, fresh read still AUTHORIZED
        // → NON-TERMINAL pending: never 'failed', never completable.
        Http::fake([
            'api.tabby.ai/api/v2/payments/'.self::PAYMENT_ID => Http::sequence()
                ->push($this->paymentResponse(), 200) // initial
                ->push($this->paymentResponse(), 200) // final arbitration read
                ->push($this->paymentResponse(), 200), // stray-proof replay guard
            'api.tabby.ai/api/v2/payments/*/captures' => Http::sequence()
                ->push(['errorType' => 'Conflict', 'error' => 'payment is locked'], 409)
                ->push(['errorType' => 'Conflict', 'error' => 'payment is locked'], 409)
                ->push(['errorType' => 'Conflict', 'error' => 'payment is locked'], 409),
        ]);

        $result = $this->gateway()->verifyPayment(self::PAYMENT_ID);

        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status, 'unresolved capture must stay pending (D1-R)');
        $this->assertNotSame('failed', $result->status);
        $this->assertSame('unresolved', $result->rawResponse['_capture']['outcome']);
        $this->assertSame(
            __('message.ERROR.PAYMENT_PROVIDER_DECLINED'),
            $result->errorMessage,
        );
    }

    /** @test */
    public function it_never_trusts_a_capture_2xx_without_the_fresh_read()
    {
        // Capture 2xx but fresh read still AUTHORIZED (closure lag) =>
        // pending "unconfirmed" — the NEXT entry decides, not this request.
        Http::fake([
            'api.tabby.ai/api/v2/payments/'.self::PAYMENT_ID => Http::sequence()
                ->push($this->paymentResponse(), 200)
                ->push($this->paymentResponse(), 200), // still AUTHORIZED
            'api.tabby.ai/api/v2/payments/*/captures' => Http::response(['id' => 'cap_1'], 200),
        ]);

        $result = $this->gateway()->verifyPayment(self::PAYMENT_ID);

        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status);
        $this->assertSame('unconfirmed', $result->rawResponse['_capture']['outcome']);
    }

    /** @test */
    public function it_does_not_capture_on_a_capture_confirmation_webhook_state()
    {
        // Docs: "A capture confirmation is not a request to capture" —
        // AUTHORIZED with captures[] pre-populated must NOT send another
        // capture; it arbitrates from the fresh read instead.
        Http::fake([
            'api.tabby.ai/api/v2/payments/'.self::PAYMENT_ID => Http::sequence()
                ->push($this->paymentResponse(overrides: [
                    'captures' => [['id' => 'cap_confirmed', 'amount' => '100.00']],
                ]), 200)
                ->push($this->paymentResponse(overrides: [
                    'captures' => [['id' => 'cap_confirmed', 'amount' => '100.00']],
                ]), 200), // still AUTHORIZED
        ]);

        $result = $this->gateway()->verifyPayment(self::PAYMENT_ID);

        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status);

        Http::assertSent(function ($request) {
            return str_ends_with((string) $request->url(), '/payments/'.self::PAYMENT_ID)
                && $request->method() === 'GET';
        });

        Http::assertNotSent(function ($request) {
            return str_contains((string) $request->url(), '/captures');
        });
    }

    /** @test */
    public function it_treats_created_as_a_non_terminal_pending_state()
    {
        Http::fake([
            'api.tabby.ai/api/v2/payments/'.self::PAYMENT_ID => Http::response($this->paymentResponse(overrides: [
                'status' => 'CREATED',
            ]), 200),
        ]);

        $result = $this->gateway()->verifyPayment(self::PAYMENT_ID);

        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status);

        Http::assertNotSent(function ($request) {
            return str_contains((string) $request->url(), '/captures');
        });
    }

    /** @test */
    public function it_maps_terminal_provider_statuses_to_a_local_failure()
    {
        foreach ([
            'REJECTED' => [],
            'EXPIRED' => [],
            'CLOSED' => [], // CLOSED with captures[] EMPTY = authority closed uncaptured
        ] as $status => $payloadOverrides) {
            Http::fake([
                'api.tabby.ai/api/v2/payments/'.self::PAYMENT_ID => Http::response($this->paymentResponse(overrides: [
                    'status' => $status,
                ] + $payloadOverrides), 200),
            ]);

            $result = $this->gateway()->verifyPayment(self::PAYMENT_ID);

            $this->assertFalse($result->success, "status {$status} must not complete");
            $this->assertSame('failed', $result->status, "status {$status} is terminal (no captures)");
            $this->assertSame(__('message.ERROR.PAYMENT_PROVIDER_DECLINED'), $result->errorMessage);
        }
    }

    /** @test */
    public function it_fails_safe_pending_on_an_ambiguous_read()
    {
        Http::fake([
            'api.tabby.ai/api/v2/payments/'.self::PAYMENT_ID => Http::response(['status' => 500], 500),
        ]);

        $result = $this->gateway()->verifyPayment(self::PAYMENT_ID);

        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status, 'unreadable truth must never be terminal');

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timeout');
        });

        $result = $this->gateway()->verifyPayment(self::PAYMENT_ID);

        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status, 'transport failure must never be terminal');
        $this->assertSame('Payment gateway error', $result->errorMessage);
    }

    /** @test */
    public function it_never_marks_paid_off_an_unrecognized_status()
    {
        Http::fake([
            'api.tabby.ai/api/v2/payments/'.self::PAYMENT_ID => Http::response($this->paymentResponse(overrides: [
                'status' => 'PAUSED', // hypothetical future status
            ]), 200),
        ]);

        $result = $this->gateway()->verifyPayment(self::PAYMENT_ID);

        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status);
    }

    /** @test */
    public function it_strips_buyer_pii_from_the_stored_verification_response()
    {
        Http::fake([
            'api.tabby.ai/api/v2/payments/'.self::PAYMENT_ID => Http::response($this->paymentResponse(overrides: [
                'status' => 'CLOSED',
                'captures' => [['id' => 'cap_1', 'amount' => '100.00', 'created_at' => '2026-01-20T09:20:00Z']],
            ]), 200),
        ]);

        $result = $this->gateway()->verifyPayment(self::PAYMENT_ID);

        $raw = $result->rawResponse ?? [];

        $this->assertStringNotContainsString('Leaked', json_encode($raw));
        $this->assertStringNotContainsString('leaked@example.com', json_encode($raw));

        // Allowlisted shape: technical reconciliation fields only.
        foreach (['id', 'status', 'amount', 'currency', 'captures'] as $key) {
            $this->assertArrayHasKey($key, $raw);
        }

        $this->assertArrayNotHasKey('buyer', $raw);
        $this->assertArrayNotHasKey('order', $raw);
    }

    /** @test */
    public function it_rejects_malformed_transaction_ids_before_any_provider_call()
    {
        foreach (['', '   ', str_repeat('a', 192), 'bad/id', 'bad id', "bad\nid"] as $id) {
            $result = $this->gateway()->verifyPayment($id);

            $this->assertFalse($result->success);
        }

        Http::assertNothingSent();
    }

    /** @test */
    public function refund_guards_fail_closed_without_a_provider_call()
    {
        // Unsupported currency and non-positive amounts are guarded BEFORE
        // the transaction lookup — provable without a database.
        $result = $this->gateway()->refund($this->order(currency: 'USD'), 5.0, 'oops');
        $this->assertFalse($result->success);
        $this->assertSame(
            __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => 'USD']),
            $result->errorMessage,
        );

        $result = $this->gateway()->refund($this->order(), 0.0, 'oops');
        $this->assertFalse($result->success);
        $this->assertSame('Invalid payment amount', $result->errorMessage);

        Http::assertNothingSent();
    }
}

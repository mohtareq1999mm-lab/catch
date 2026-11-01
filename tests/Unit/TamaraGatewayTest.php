<?php

namespace Tests\Unit;

use App\Services\Gateway\TamaraGateway;
use App\Services\Payment\Contracts\AuthorisationGatewayContract;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\Order;
use Tests\TestCase;

/**
 * TamaraGateway adapter unit tests — DB-free (MySQL unreachable in this
 * environment; see docs/audits/tamara-payment-integration-audit.md and the
 * Tabby precedent tests/Unit/TabbyGatewayTest.php).
 *
 * Every provider interaction runs through Http::fake() +
 * Http::preventStrayRequests() — no test touches the network, and any code
 * path that reaches Tamara without a matching fake fails loudly. The order
 * fixture is a non-persisted model: PaymentCurrencyResolver (catalog
 * currency) and CustomerContactResolver read attributes only on these
 * guarded paths. Refund paths (transactions() relation) and webhook
 * completion flows are catalogued as FEATURE-test work (skeletons in
 * tests/Feature/Payment) because they require rows.
 *
 * The behavioral contracts tested here are the authorised-money-moment and
 * no-invented-idempotency guarantees:
 *   - fresh authorised / *_captured / *_refunded   => success + paid (once,
 *     upstream); declined/expired/canceled => failed; updated/new/unknown/
 *     transport-lost => pending (never a terminal decision on unread truth);
 *   - approved => ONE authorise POST bounded per verification; the fresh
 *     read that follows decides (payload never completes by itself);
 *   - capture: fresh-read pre-fence refuses already-captured money and
 *     money-state divergence; ambiguous never blind-resends; a resend only
 *     happens after a fresh read proves zero captured.
 */
class TamaraGatewayTest extends TestCase
{
    private const ORDER_ID = '870e3e2e-e88a-4933-9ec0-7f7b8ed65e0c';

    private const CONFIG = [
        'class' => TamaraGateway::class,
        'enabled' => true,
        'environment' => 'sandbox',
        'api_url' => null,
        'api_token' => 'tamara_unit_api_token',
        'notification_token' => 'tamara_unit_notification_token',
        'public_key' => null,
        'supported_currencies' => ['SAR', 'AED', 'BHD', 'KWD', 'OMR'],
        'eligibility_timeout_ms' => 200,
        'capture_attempts' => 2,
        'payment_type' => 'PAY_BY_INSTALMENTS',
        'instalments' => 3,
        'platform_label' => 'Meem',
        'methods' => ['online'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['payment.gateways.tamara' => self::CONFIG]);

        Http::preventStrayRequests();
    }

    private function gateway(): TamaraGateway
    {
        return app(TamaraGateway::class);
    }

    private function order(string $currency = 'KWD', float $total = 30.5): Order
    {
        $order = new Order();
        $order->forceFill([
            'id' => 7,
            'order_number' => 'MEEM-7',
            'name' => 'Test Tamara Buyer',
            'user_phone' => '966500000001',
            'address' => ['city' => 'Riyadh', 'street' => 'Al Urubah Rd', 'zip' => '12211', 'country_code' => 'SA'],
            'total_price' => $total,
            'shipping_price' => 2.0,
            'fast_shipping_fee' => 0.0,
            'product_tax_amount' => 1.5,
            'order_tax_amount' => 0.0,
            'coupon_discount' => 0.0,
            'promotion_discount' => 0.0,
            'catalog_currency_code' => $currency,
            'created_at' => now(),
        ]);

        return $order;
    }

    private function checkoutResponse(array $overrides = []): array
    {
        return array_merge([
            'order_id' => self::ORDER_ID,
            'checkout_id' => 'checkout-unit-0001',
            'status' => 'new',
            'checkout_url' => 'https://checkout.tamara.co/checkout?orderId=' . self::ORDER_ID,
        ], $overrides);
    }

    private function orderResponse(array $overrides = []): array
    {
        return array_merge([
            'order_id' => self::ORDER_ID,
            'checkout_id' => 'checkout-unit-0001',
            'status' => 'authorised',
            'order_reference_id' => 'ref-unit-0001',
            'order_number' => 'MEEM-7',
            'total_amount' => ['amount' => 30.5, 'currency' => 'KWD'],
            'shipping_amount' => ['amount' => 2, 'currency' => 'KWD'],
            'tax_amount' => ['amount' => 1.5, 'currency' => 'KWD'],
            'discount' => ['name' => 'coupon-unit', 'amount' => ['amount' => 0, 'currency' => 'KWD']],
            'amounts' => [
                'captured_amount' => ['amount' => 0, 'currency' => 'KWD'],
                'refunded_amount' => ['amount' => 0, 'currency' => 'KWD'],
                'canceled_amount' => ['amount' => 0, 'currency' => 'KWD'],
                'paid_amount' => ['amount' => 30.5, 'currency' => 'KWD'],
            ],
            'consumer' => [
                'first_name' => 'PII-Consumer',
                'last_name' => 'PII-Leak',
                'email' => 'leak@example.com',
                'phone_number' => '966500000001',
            ],
            'shipping_address' => ['line1' => 'PII-address'],
            'transactions' => [
                'captures' => [],
                'cancels' => [],
                'refunds' => [],
            ],
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // Identity / configuration / currency contract
    // -----------------------------------------------------------------

    /** @test */
    public function reports_name_code_and_contract_membership(): void
    {
        $gateway = $this->gateway();

        $this->assertSame('Tamara', $gateway->name());
        $this->assertSame('tamara', $gateway->code());
        $this->assertTrue($gateway->isConfigured());
        $this->assertInstanceOf(PaymentGatewayContract::class, $gateway);
        $this->assertInstanceOf(AuthorisationGatewayContract::class, $gateway);
    }

    /** @test */
    public function unconfigured_when_tokens_missing(): void
    {
        config(['payment.gateways.tamara.api_token' => null]);

        $this->assertFalse($this->gateway()->isConfigured());

        config(['payment.gateways.tamara.api_token' => 'x', 'payment.gateways.tamara.notification_token' => '']);

        $this->assertFalse($this->gateway()->isConfigured());
    }

    /** @test */
    public function base_url_resolves_by_environment_and_api_url_override_wins(): void
    {
        config(['payment.gateways.tamara.api_url' => 'https://tamara.unit.test/']);

        try {
            Http::fake([
                'tamara.unit.test/orders/*' => Http::response($this->orderResponse(), 200),
            ]);

            $result = $this->gateway()->verifyPayment(self::ORDER_ID);

            $this->assertTrue($result->success);
            Http::assertSentInOrder([fn ($req) => str_starts_with($req->url(), 'https://tamara.unit.test/orders/')]);
        } finally {
            config(['payment.gateways.tamara.api_url' => null]);
        }
    }

    /** @test */
    public function unknown_environment_fails_closed(): void
    {
        config(['payment.gateways.tamara.api_url' => null, 'payment.gateways.tamara.environment' => 'staging']);

        // Unknown environment ⇒ unresolvable base URL: the adapter refuses
        // to call any host (fail-closed, no exception leakage to callers).
        Http::fake();
        Http::assertNothingSent();

        $result = $this->gateway()->createInvoice($this->order(), 30.5, 'https://cb', 'https://err');

        $this->assertFalse($result->success);
        $this->assertSame('Payment gateway is not configured', $result->errorMessage);
        Http::assertNothingSent();
    }

    /** @test */
    public function supports_currency_respects_documented_enum_and_config(): void
    {
        $gateway = $this->gateway();

        $this->assertTrue($gateway->supportsCurrency('KWD'));
        $this->assertTrue($gateway->supportsCurrency('sar'));
        $this->assertTrue($gateway->supportsCurrency('AED'));
        $this->assertTrue($gateway->supportsCurrency('BHD'));
        $this->assertTrue($gateway->supportsCurrency('OMR'));

        // Config-narrowed but inside the enum.
        config(['payment.gateways.tamara.supported_currencies' => ['SAR']]);
        $this->assertFalse($gateway->supportsCurrency('KWD'));

        // Outside the documented enum: refused even if misconfigured in.
        config(['payment.gateways.tamara.supported_currencies' => ['USD', 'KWD']]);
        $this->assertFalse($gateway->supportsCurrency('USD'));
    }

    // -----------------------------------------------------------------
    // Checkout session creation
    // -----------------------------------------------------------------

    /** @test */
    public function create_invoice_builds_composed_payload_and_returns_redirect(): void
    {
        Http::fake([
            'api-sandbox.tamara.co/checkout' => Http::response($this->checkoutResponse(), 201),
        ]);

        $result = $this->gateway()->createInvoice($this->order(), 30.5, 'https://cb', 'https://err');

        $this->assertTrue($result->success);
        $this->assertSame(self::ORDER_ID, $result->gatewayTransactionId);
        $this->assertSame('https://checkout.tamara.co/checkout?orderId=' . self::ORDER_ID, $result->redirectUrl);
        $this->assertSame(30.5, $result->amount);
        $this->assertSame('KWD', $result->currency);
        $this->assertSame('pending', $result->status);

        Http::assertSentInOrder([function ($request): bool {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://api-sandbox.tamara.co/checkout', $request->url());
            $this->assertSame('Bearer tamara_unit_api_token', $request->header('Authorization')[0]);

            $payload = $request->data();

            // Documented composition: items + tax + shipping - discount == total
            $this->assertSame(30.5, $payload['total_amount']['amount']);
            $this->assertSame('KWD', $payload['total_amount']['currency']);
            $this->assertSame(1.5, $payload['tax_amount']['amount']);
            $this->assertSame(2.0, $payload['shipping_amount']['amount']);
            $this->assertSame(27.0, $payload['items'][0]['total_amount']['amount']);
            $this->assertSame(1, count($payload['items']));

            // Documented required fields present.
            $this->assertSame('PAY_BY_INSTALMENTS', $payload['payment_type']);
            $this->assertSame(3, $payload['instalments']);
            $this->assertSame('SA', $payload['country_code']);
            $this->assertSame('https://cb', $payload['merchant_url']['success']);
            $this->assertSame('https://cb', $payload['merchant_url']['failure']);
            $this->assertSame('https://cb', $payload['merchant_url']['cancel']);
            $this->assertSame('MEEM-7', $payload['order_number']);
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f\-]{36}$/i',
                $payload['order_reference_id'],
            );

            // No invented PII beyond the required consumer block (which carries
            // only the sanitized gateway contacts).
            $this->assertArrayHasKey('consumer', $payload);
            $this->assertSame('966500000001', $payload['consumer']['phone_number']);

            // shipping_address (§3 required inventory) mirrors buyer identity
            // + the saved-address snapshot keys (Tabby precedent street/city).
            $this->assertSame('Test', $payload['shipping_address']['first_name']);
            $this->assertSame('Tamara Buyer', $payload['shipping_address']['last_name']);
            $this->assertSame('Al Urubah Rd', $payload['shipping_address']['line1']);
            $this->assertSame('Riyadh', $payload['shipping_address']['city']);
            $this->assertSame('SA', $payload['shipping_address']['country_code']);

            // Item schema'd fields ride the same decomposition: unit_price ==
            // the line total (qty 1); item-level tax/discount ZEROS — the
            // order-level tax/discount objects remain the only sources.
            $this->assertSame(27.0, $payload['items'][0]['unit_price']['amount']);
            $this->assertSame(0.0, $payload['items'][0]['tax_amount']['amount']);
            $this->assertSame(0.0, $payload['items'][0]['discount_amount']['amount']);

            return true;
        }]);

        // Persisted raw response allowlist: identity refs only — no consumer
        // PII, no raw echo blob.
        $this->assertArrayHasKey('order_id', $result->rawResponse);
        $this->assertArrayHasKey('checkout_id', $result->rawResponse);
        $this->assertArrayNotHasKey('checkout_url', $result->rawResponse);
        $this->assertArrayNotHasKey('consumer', $result->rawResponse);
    }

    /** @test */
    public function create_invoice_refuses_unsupported_currency_without_calling_provider(): void
    {
        Http::fake();
        Http::assertNothingSent();

        $result = $this->gateway()->createInvoice($this->order('USD'), 30.5, 'https://cb', 'https://err');

        $this->assertFalse($result->success);
        $this->assertSame(
            __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => 'USD']),
            $result->errorMessage,
        );
        Http::assertNothingSent();
    }

    /** @test */
    public function create_invoice_fails_closed_on_provider_error_and_redacts(): void
    {
        // The token-shaped run is ≥40 chars — the Tabby-mirrored redaction
        // threshold (/[A-Za-z0-9\-_]{40,}/) must turn it into [redacted].
        Http::fake([
            'api-sandbox.tamara.co/checkout' => Http::response([
                'message' => 'Risk assessment rejected tamara.Unit-Token-000000000000000000000000000000',
            ], 422),
        ]);

        $result = $this->gateway()->createInvoice($this->order(), 30.5, 'https://cb', 'https://err');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('[redacted]', $result->errorMessage);
        $this->assertStringNotContainsString('Unit-Token-000000000000000000000000000000', $result->errorMessage);
    }

    /** @test */
    public function create_invoice_fails_when_provider_omits_required_fields(): void
    {
        // Missing checkout_url ⇒ no attributable redirect.
        Http::fake([
            'api-sandbox.tamara.co/checkout' => Http::response(['order_id' => self::ORDER_ID, 'status' => 'new'], 201),
        ]);

        $result = $this->gateway()->createInvoice($this->order(), 30.5, 'https://cb', 'https://err');

        $this->assertFalse($result->success);

        // Missing order_id ⇒ no attributable payment.
        Http::fake([
            'api-sandbox.tamara.co/checkout' => Http::response(['checkout_url' => 'https://checkout.tamara.co/x', 'status' => 'new'], 201),
        ]);

        $result = $this->gateway()->createInvoice($this->order(), 30.5, 'https://cb', 'https://err');

        $this->assertFalse($result->success);
    }

    /** @test */
    public function create_invoice_fair_transport_failure_is_fail_closed(): void
    {
        Http::fake(function (): \GuzzleHttp\Psr7\Response {
            throw new ConnectionException('connection refused');
        });

        $result = $this->gateway()->createInvoice($this->order(), 30.5, 'https://cb', 'https://err');

        $this->assertFalse($result->success);
        $this->assertSame('Payment gateway error', $result->errorMessage);
    }

    // -----------------------------------------------------------------
    // Fresh-read mapping (the money-state authority)
    // -----------------------------------------------------------------

    /** @test */
    public function authorised_and_captured_families_map_to_paid_with_provider_echo(): void
    {
        $statuses = ['authorised', 'partially_captured', 'fully_captured', 'partially_refunded', 'fully_refunded'];

        foreach ($statuses as $status) {
            Http::fake([
                'api-sandbox.tamara.co/orders/*' => Http::response($this->orderResponse([
                    'status' => $status,
                    'total_amount' => ['amount' => 33.333, 'currency' => 'KWD'],
                ]), 200),
            ]);

            $result = $this->gateway()->verifyPayment(self::ORDER_ID);

            $this->assertTrue($result->success, "status $status should be success");
            $this->assertSame('paid', $result->status, "status $status should map to paid");
            $this->assertSame(33.333, $result->amount, "status $status should echo the provider amount");
            $this->assertSame('KWD', $result->currency);
            $this->assertSame(self::ORDER_ID, $result->gatewayTransactionId);
        }
    }

    /** @test */
    public function terminal_negative_statuses_map_to_failed(): void
    {
        foreach (['declined', 'expired', 'canceled'] as $status) {
            Http::fake([
                'api-sandbox.tamara.co/orders/*' => Http::response($this->orderResponse(['status' => $status]), 200),
            ]);

            $result = $this->gateway()->verifyPayment(self::ORDER_ID);

            $this->assertFalse($result->success, "status $status should not succeed");
            $this->assertSame('failed', $result->status, "status $status should map to failed");
        }
    }

    /** @test */
    public function non_terminal_statuses_map_to_pending(): void
    {
        foreach (['new', 'updated', 'weird_new_status_2030', ''] as $status) {
            Http::fake([
                'api-sandbox.tamara.co/orders/*' => Http::response($this->orderResponse(['status' => $status]), 200),
            ]);

            $result = $this->gateway()->verifyPayment(self::ORDER_ID);

            $this->assertFalse($result->success, "status $status should not succeed");
            $this->assertSame('pending', $result->status, "status $status should map to pending");
        }
    }

    /** @test */
    public function transport_failure_on_fresh_read_is_pending_never_terminal(): void
    {
        Http::fake(function (): \GuzzleHttp\Psr7\Response {
            throw new ConnectionException('connection refused');
        });

        $result = $this->gateway()->verifyPayment(self::ORDER_ID);

        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status);
    }

    /** @test */
    public function http_error_on_fresh_read_is_pending_never_terminal(): void
    {
        Http::fake([
            'api-sandbox.tamara.co/orders/*' => Http::response(['message' => 'boom'], 503),
        ]);

        $result = $this->gateway()->verifyPayment(self::ORDER_ID);

        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status);
    }

    /** @test */
    public function invalid_gateway_id_is_refused_without_network(): void
    {
        Http::fake();
        Http::assertNothingSent();

        foreach (['', 'invalid id with space', str_repeat('a', 200)] as $id) {
            $result = $this->gateway()->verifyPayment($id);
            $this->assertFalse($result->success);
        }

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // approved → authorise arbiter
    // -----------------------------------------------------------------

    /** @test */
    public function approved_triggers_exactly_one_authorise_then_arbitrates_from_fresh_read(): void
    {
        Http::fake([
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID => Http::sequence()
                ->push($this->orderResponse(['status' => 'approved']), 200)
                ->push($this->orderResponse(['status' => 'authorised']), 200),
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID . '/authorise' => Http::response(['order_id' => self::ORDER_ID, 'auto_captured' => false, 'capture_id' => null], 200),
        ]);

        $result = $this->gateway()->verifyPayment(self::ORDER_ID);

        $this->assertTrue($result->success);
        $this->assertSame('paid', $result->status);

        // Exactly ONE authorise POST for the delivered `approved` read.
        Http::assertSentInOrder([
            fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/orders/' . self::ORDER_ID),
            fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/orders/' . self::ORDER_ID . '/authorise'),
            fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/orders/' . self::ORDER_ID),
        ]);
    }

    /** @test */
    public function approved_stuck_after_authorise_is_a_bounded_single_attempt(): void
    {
        $getCount = 0;
        $postCount = 0;

        Http::fake([
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID => function () use (&$getCount) {
                $getCount++;

                return Http::response($this->orderResponse(['status' => 'approved']), 200);
            },
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID . '/authorise' => function () use (&$postCount) {
                $postCount++;

                return Http::response(['message' => 'authorise accepted'], 200);
            },
        ]);

        $result = $this->gateway()->verifyPayment(self::ORDER_ID);

        // Still approved after an accepted authorise: pending (never loops).
        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status);
        $this->assertSame(2, $getCount); // initial read + post-authorise fresh read
        $this->assertSame(1, $postCount); // bounded: one authorise per verification
    }

    /** @test */
    public function approved_with_authorise_transport_failure_stays_pending(): void
    {
        $getCount = 0;

        Http::fake([
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID => function () use (&$getCount) {
                $getCount++;

                if ($getCount === 1) {
                    return Http::response($this->orderResponse(['status' => 'approved']), 200);
                }

                throw new ConnectionException('connection refused');
            },
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID . '/authorise' => Http::response(['ok' => true], 200),
        ]);

        $result = $this->gateway()->verifyPayment(self::ORDER_ID);

        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status);
    }

    // -----------------------------------------------------------------
    // Fresh-read sanitization
    // -----------------------------------------------------------------

    /** @test */
    public function fresh_read_persists_allowlisted_evidence_only(): void
    {
        Http::fake([
            'api-sandbox.tamara.co/orders/*' => Http::response($this->orderResponse([
                'transactions' => [
                    'captures' => [['capture_id' => 'cap-1', 'amount' => 30.5, 'created' => '2026-10-31T00:00:00Z']],
                    'refunds' => [['refund_id' => 'ref-1', 'amount' => 1, 'created' => '2026-10-31T01:00:00Z']],
                    'cancels' => [],
                ],
            ]), 200),
        ]);

        $result = $this->gateway()->verifyPayment(self::ORDER_ID);

        $raw = $result->rawResponse;

        $this->assertArrayHasKey('order_id', $raw);
        $this->assertArrayHasKey('status', $raw);
        $this->assertArrayHasKey('total_amount', $raw);
        $this->assertArrayHasKey('amounts', $raw);
        $this->assertSame([['capture_id' => 'cap-1', 'amount' => 30.5, 'created' => '2026-10-31T00:00:00Z']], $raw['captures']);
        $this->assertSame([['refund_id' => 'ref-1', 'amount' => 1, 'created' => '2026-10-31T01:00:00Z']], $raw['refunds']);
        $this->assertSame([], $raw['cancels']);

        // PII and provider internals stripped at the source.
        $this->assertArrayNotHasKey('consumer', $raw);
        $this->assertArrayNotHasKey('shipping_address', $raw);

        $serialized = json_encode($raw);
        $this->assertStringNotContainsString('leak@example.com', $serialized);
        $this->assertStringNotContainsString('966500000001', $serialized);
        $this->assertStringNotContainsString('PII', $serialized);
    }

    // -----------------------------------------------------------------
    // Shipment capture
    // -----------------------------------------------------------------

    private function shipment(): array
    {
        return [
            'fulfillment_id' => 11,
            'shipped_at' => '2026-10-31T08:00:00Z',
            'shipping_company' => 'Aramex',
            'tracking_number' => 'TRK-1',
            'items' => [
                [
                    'reference_id' => 'MEEM-7',
                    'sku' => 'MEEM-7',
                    'name' => 'Order MEEM-7',
                    'quantity' => 1,
                    'unit_price' => 30.5,
                    'tax_amount' => 0.0,
                    'discount_amount' => 0.0,
                    'total_amount' => 30.5,
                ],
            ],
        ];
    }

    /** @test */
    public function capture_records_on_2xx_with_capture_id(): void
    {
        Http::fake([
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID => Http::sequence()
                ->push($this->orderResponse(['status' => 'authorised']), 200),
            'api-sandbox.tamara.co/payments/capture' => Http::response([
                'capture_id' => 'capture-unit-1',
                'order_id' => self::ORDER_ID,
                'status' => 'fully_captured',
            ], 200),
        ]);

        $result = $this->gateway()->captureFromShipment($this->order(), self::ORDER_ID, $this->shipment());

        $this->assertTrue($result->success);
        $this->assertSame('capture_recorded', $result->status);
        $this->assertSame('capture-unit-1', $result->rawResponse['_capture_id']);
        $this->assertSame(30.5, $result->amount);
        $this->assertSame('KWD', $result->currency);

        Http::assertSentInOrder([
            fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/orders/'),
            fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/payments/capture'),
        ]);
    }

    /** @test */
    public function capture_payload_carries_shipping_info_and_items(): void
    {
        Http::fake([
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID => Http::sequence()
                ->push($this->orderResponse(['status' => 'authorised']), 200),
            'api-sandbox.tamara.co/payments/capture' => Http::response(['capture_id' => 'cap-2'], 200),
        ]);

        $this->assertTrue($this->gateway()->captureFromShipment($this->order(), self::ORDER_ID, $this->shipment())->success);

        Http::assertSentInOrder([
            fn ($r) => $r->method() === 'GET',
            function ($r): bool {
                $payload = $r->data();

                $this->assertSame('POST', $r->method());
                $this->assertSame(self::ORDER_ID, $payload['order_id']);
                $this->assertSame(30.5, $payload['total_amount']['amount']);
                $this->assertSame('KWD', $payload['total_amount']['currency']);
                $this->assertSame('2026-10-31T08:00:00Z', $payload['shipping_info']['shipped_at']);
                $this->assertSame('Aramex', $payload['shipping_info']['shipping_company']);
                $this->assertSame('TRK-1', $payload['shipping_info']['tracking_number']);
                $this->assertSame(1, count($payload['items']));
                $this->assertSame(30.5, $payload['items'][0]['total_amount']['amount']);

                return true;
            },
        ]);
    }

    /** @test */
    public function capture_refuses_when_provider_already_captured(): void
    {
        Http::fake([
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID => Http::sequence()
                ->push($this->orderResponse(['status' => 'fully_captured', 'amounts' => [
                    'captured_amount' => ['amount' => 30.5, 'currency' => 'KWD'],
                    'refunded_amount' => ['amount' => 0, 'currency' => 'KWD'],
                    'canceled_amount' => ['amount' => 0, 'currency' => 'KWD'],
                    'paid_amount' => ['amount' => 30.5, 'currency' => 'KWD'],
                ]]), 200),
        ]);

        $result = $this->gateway()->captureFromShipment($this->order(), self::ORDER_ID, $this->shipment());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('already exists', $result->errorMessage);

        // Only the fence read happened — no capture POST was sent.
        Http::assertSentInOrder([fn ($r) => $r->method() === 'GET']);
    }

    /** @test */
    public function capture_refuses_on_non_capturable_state(): void
    {
        Http::fake([
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID => Http::sequence()
                ->push($this->orderResponse(['status' => 'declined']), 200),
        ]);

        $result = $this->gateway()->captureFromShipment($this->order(), self::ORDER_ID, $this->shipment());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('declined', $result->errorMessage);
    }

    /** @test */
    public function capture_refuses_over_capture_beyond_authorised(): void
    {
        Http::fake([
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID => Http::sequence()
                ->push($this->orderResponse(['status' => 'authorised']), 200),
        ]);

        $shipment = $this->shipment();
        $shipment['items'][0]['total_amount'] = 999.0;

        $result = $this->gateway()->captureFromShipment($this->order(), self::ORDER_ID, $shipment);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('exceed', $result->errorMessage);
        Http::assertSentInOrder([fn ($r) => $r->method() === 'GET']);
    }

    /** @test */
    public function capture_fails_closed_when_prefence_read_is_unreadable(): void
    {
        $getCount = 0;

        Http::fake([
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID => function () use (&$getCount) {
                $getCount++;

                throw new ConnectionException('connection refused');
            },
        ]);

        $result = $this->gateway()->captureFromShipment($this->order(), self::ORDER_ID, $this->shipment());

        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status);
        $this->assertSame(1, $getCount); // unreadable truth: never send blind
    }

    /** @test */
    public function capture_transport_failure_is_pending_never_blind_resent(): void
    {
        $getCount = 0;

        Http::fake([
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID => function () use (&$getCount) {
                $getCount++;

                return Http::response($this->orderResponse(['status' => 'authorised']), 200);
            },
            'api-sandbox.tamara.co/payments/capture' => function (): \GuzzleHttp\Psr7\Response {
                throw new ConnectionException('connection refused');
            },
        ]);

        $result = $this->gateway()->captureFromShipment($this->order(), self::ORDER_ID, $this->shipment());

        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status);
        $this->assertSame(1, $getCount); // no blind re-read, no blind re-send
    }

    /** @test */
    public function capture_5xx_then_fresh_read_captured_is_recorded_no_resent(): void
    {
        $getCount = 0;
        $postCount = 0;

        Http::fake([
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID => function () use (&$getCount) {
                $getCount++;
                $status = $getCount <= 1 ? 'authorised' : 'fully_captured';

                return Http::response($this->orderResponse([
                    'status' => $status,
                    'amounts' => [
                        'captured_amount' => ['amount' => $status === 'fully_captured' ? 30.5 : 0, 'currency' => 'KWD'],
                        'refunded_amount' => ['amount' => 0, 'currency' => 'KWD'],
                        'canceled_amount' => ['amount' => 0, 'currency' => 'KWD'],
                        'paid_amount' => ['amount' => 30.5, 'currency' => 'KWD'],
                    ],
                ]), 200);
            },
            'api-sandbox.tamara.co/payments/capture' => function () use (&$postCount) {
                $postCount++;

                return Http::response(['message' => 'gateway error'], 500);
            },
        ]);

        $result = $this->gateway()->captureFromShipment($this->order(), self::ORDER_ID, $this->shipment());

        // The provider captured the money despite the 5xx (raced): honest
        // pending dedupe for the caller, never a retry that would double-capture.
        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status);
        $this->assertStringContainsString('already exists', $result->errorMessage);
        $this->assertSame(1, $postCount);
        $this->assertSame(2, $getCount);
    }

    /** @test */
    public function capture_5xx_then_still_authorised_earns_exactly_one_resend(): void
    {
        $getCount = 0;
        $postCount = 0;

        Http::fake([
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID => function () use (&$getCount) {
                $getCount++;

                return Http::response($this->orderResponse(['status' => 'authorised']), 200);
            },
            'api-sandbox.tamara.co/payments/capture' => function () use (&$postCount) {
                $postCount++;

                if ($postCount === 1) {
                    return Http::response(['message' => 'gateway hiccup'], 500);
                }

                return Http::response(['capture_id' => 'cap-3'], 200);
            },
        ]);

        $result = $this->gateway()->captureFromShipment($this->order(), self::ORDER_ID, $this->shipment());

        $this->assertTrue($result->success);
        $this->assertSame('capture_recorded', $result->status);
        $this->assertSame('cap-3', $result->rawResponse['_capture_id']);
        $this->assertSame(2, $postCount);
        $this->assertSame(2, $getCount); // pre-fence + arbitration reads only
    }

    // -----------------------------------------------------------------
    // Provider cancellation (authorised, uncaptured)
    // -----------------------------------------------------------------

    /** @test */
    public function cancel_authorised_confirmed(): void
    {
        Http::fake([
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID => Http::sequence()
                ->push($this->orderResponse(['status' => 'authorised']), 200),
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID . '/cancel' => Http::response([
                'order_id' => self::ORDER_ID,
                'canceled_amount' => ['amount' => 30.5, 'currency' => 'KWD'],
                'status' => 'canceled',
            ], 200),
        ]);

        $result = $this->gateway()->cancelAuthorised($this->order(), self::ORDER_ID);

        $this->assertTrue($result->success);
        $this->assertSame('cancelled', $result->status);

        Http::assertSentInOrder([
            fn ($r) => $r->method() === 'GET',
            fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/cancel'),
        ]);
    }

    /** @test */
    public function cancel_racing_past_to_captured_refuses_honestly(): void
    {
        $getCount = 0;

        Http::fake([
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID => function () use (&$getCount) {
                $getCount++;

                if ($getCount === 1) {
                    return Http::response($this->orderResponse(['status' => 'authorised']), 200);
                }

                // Post-409 arbitration read: the provider captured meanwhile.
                return Http::response($this->orderResponse(['status' => 'fully_captured', 'amounts' => [
                    'captured_amount' => ['amount' => 30.5, 'currency' => 'KWD'],
                    'refunded_amount' => ['amount' => 0, 'currency' => 'KWD'],
                    'canceled_amount' => ['amount' => 0, 'currency' => 'KWD'],
                    'paid_amount' => ['amount' => 30.5, 'currency' => 'KWD'],
                ]]), 200);
            },
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID . '/cancel' => Http::response([
                'message' => 'The order ' . self::ORDER_ID . ' cannot be moved to the status canceled from authorised',
                'errors' => [
                    ['error_code' => 'transition_not_allowed', 'data' => ['old_state' => 'authorised', 'new_state' => 'canceled']],
                ],
            ], 409),
        ]);

        $result = $this->gateway()->cancelAuthorised($this->order(), self::ORDER_ID);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('refund via captured money instead', $result->errorMessage);
        $this->assertSame(2, $getCount);
    }

    /** @test */
    public function cancel_on_captured_order_is_a_hard_refusal(): void
    {
        Http::fake([
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID => Http::sequence()
                ->push($this->orderResponse(['status' => 'fully_captured', 'amounts' => [
                    'captured_amount' => ['amount' => 30.5, 'currency' => 'KWD'],
                    'refunded_amount' => ['amount' => 0, 'currency' => 'KWD'],
                    'canceled_amount' => ['amount' => 0, 'currency' => 'KWD'],
                    'paid_amount' => ['amount' => 30.5, 'currency' => 'KWD'],
                ]]), 200),
        ]);

        $result = $this->gateway()->cancelAuthorised($this->order(), self::ORDER_ID);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('cancellation is not a refund', $result->errorMessage);
        Http::assertSentInOrder([fn ($r) => $r->method() === 'GET']);
    }

    /** @test */
    public function cancel_on_already_canceled_is_benign_dedupe(): void
    {
        Http::fake([
            'api-sandbox.tamara.co/orders/' . self::ORDER_ID => Http::sequence()
                ->push($this->orderResponse(['status' => 'canceled']), 200),
        ]);

        $result = $this->gateway()->cancelAuthorised($this->order(), self::ORDER_ID);

        $this->assertTrue($result->success);
        $this->assertSame('cancelled', $result->status);
    }

    // -----------------------------------------------------------------
    // Pre-checkout eligibility (fail-open, 200ms budget)
    // -----------------------------------------------------------------

    /** @test */
    public function eligibility_reports_provider_verdict(): void
    {
        // Single mutating fake (stub callbacks are CUMULATIVE in Laravel's
        // Http facade — a second Http::fake() can never override an earlier
        // URL stub). The call counter also proves the request actually runs
        // against the provider real-fake: a swallowed stray or budget
        // TypeError previously turned this into a silent fail-open.
        $calls = 0;

        Http::fake([
            'api-sandbox.tamara.co/pre-checkout/v1/eligibility' => function () use (&$calls) {
                $calls++;

                return Http::response(['is_eligible' => $calls === 2], 200);
            },
        ]);

        $this->assertFalse($this->gateway()->isEligible(30.5, 'KWD', '966500000001', 'buyer@example.com'));
        $this->assertTrue($this->gateway()->isEligible(30.5, 'KWD', '966500000001', 'buyer@example.com'));
        $this->assertSame(2, $calls);
    }

    /** @test */
    public function eligibility_fail_open_on_timeout_and_malformed(): void
    {
        // Stub callbacks are CUMULATIVE: one counter-driven fake so each
        // phase genuinely exercises its fail-open path (transport failure →
        // HTTP error → malformed body), never a re-matched first stub.
        $calls = 0;

        Http::fake(function () use (&$calls) {
            return match (++$calls) {
                1 => throw new ConnectionException('timeout'),
                2 => Http::response(['unexpected' => 'shape'], 502),
                default => Http::response(['no_is_eligible_key' => true], 200),
            };
        });

        // Phase 1 — transport failure/timeout budget.
        $this->assertTrue($this->gateway()->isEligible(30.5, 'KWD', '966500000001', null));
        // Phase 2 — HTTP error status.
        $this->assertTrue($this->gateway()->isEligible(30.5, 'KWD', '966500000001', null));
        // Phase 3 — 200 body without the is_eligible key.
        $this->assertTrue($this->gateway()->isEligible(30.5, 'KWD', '966500000001', null));

        $this->assertSame(3, $calls);
    }

    /** @test */
    public function eligibility_fails_open_for_unsupported_currency_or_unconfigured_gateway(): void
    {
        Http::fake();
        Http::assertNothingSent();

        $this->assertTrue($this->gateway()->isEligible(30.5, 'USD', '966500000001', null));

        config(['payment.gateways.tamara.api_token' => null]);
        $this->assertTrue($this->gateway()->isEligible(30.5, 'KWD', '966500000001', null));
        Http::assertNothingSent();
    }
}

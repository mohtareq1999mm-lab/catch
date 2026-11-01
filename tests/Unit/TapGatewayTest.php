<?php

namespace Tests\Unit;

use App\DTOs\GatewayResult;
use App\Services\Gateway\TapGateway;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\Order;
use Tests\TestCase;

/**
 * TapGateway adapter unit tests — DB-free (MySQL unreachable in this
 * environment; see docs/audits/tap-payments-integration-audit.md).
 *
 * Every provider interaction runs through Http::fake() +
 * Http::preventStrayRequests() — no test below ever touches the network,
 * and any code path that reaches Tap without a matching fake fails loudly.
 * The order fixture is a non-persisted model: PaymentCurrencyResolver and
 * CustomerContactResolver read attributes only on these guarded paths.
 * Refund DB paths (transactions() relation) are covered by the catalogued
 * feature test instead.
 */
class TapGatewayTest extends TestCase
{
    private const CONFIG = [
        'class' => TapGateway::class,
        'enabled' => true,
        'secret_key' => 'sk_test_unit_secret',
        'base_url' => 'https://api.tap.company/v2',
        'source_id' => 'src_all',
        'supported_currencies' => ['AED', 'BHD', 'EGP', 'EUR', 'GBP', 'JOD', 'KWD', 'OMR', 'QAR', 'SAR', 'USD'],
        'methods' => ['online'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['payment.gateways.tap' => self::CONFIG]);

        Http::preventStrayRequests();
    }

    private function gateway(): TapGateway
    {
        return app(TapGateway::class);
    }

    private function order(string $currency = 'KWD', float $total = 13.2549): Order
    {
        $order = new Order();
        $order->forceFill([
            'id' => 5,
            'name' => 'Test Customer',
            'total_price' => $total,
            'catalog_currency_code' => $currency,
        ]);

        return $order;
    }

    private function chargeResponse(array $overrides = []): array
    {
        return array_merge([
            'id' => 'chg_test123',
            'status' => 'INITIATED',
            'amount' => 13.255,
            'currency' => 'KWD',
            'customer' => [
                'first_name' => 'Leaked',
                'email' => 'leaked@example.com',
            ],
            'card' => [
                'first_six' => '424242',
                'last_four' => '4242',
            ],
            'transaction' => [
                'url' => 'https://gate.tap.company/redirect/chg_test123',
            ],
        ], $overrides);
    }

    /** @test */
    public function it_implements_the_gateway_contract()
    {
        $this->assertInstanceOf(PaymentGatewayContract::class, $this->gateway());
        $this->assertSame('tap', $this->gateway()->code());
        $this->assertSame('tap', $this->gateway()->name());
        $this->assertTrue($this->gateway()->isConfigured());
        $this->assertTrue($this->gateway()->supportsCurrency('KWD'));
        $this->assertFalse($this->gateway()->supportsCurrency('XYZ'));
    }

    /** @test */
    public function it_is_not_configured_without_a_secret_key()
    {
        config(['payment.gateways.tap.secret_key' => null]);

        $this->assertFalse($this->gateway()->isConfigured());
    }

    /** @test */
    public function it_creates_a_charge_per_the_documented_contract()
    {
        Http::fake([
            'api.tap.company/v2/charges' => Http::response($this->chargeResponse(), 200),
        ]);

        $result = $this->gateway()->createInvoice(
            $this->order(),
            13.2549,
            'https://app.test/callback',
            'https://app.test/error',
        );

        $this->assertInstanceOf(GatewayResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame('https://gate.tap.company/redirect/chg_test123', $result->redirectUrl);
        $this->assertSame('chg_test123', $result->gatewayTransactionId);
        $this->assertSame(13.255, $result->amount); // rounded at the KWD exponent
        $this->assertSame('KWD', $result->currency);
        $this->assertSame('pending', $result->status);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.tap.company/v2/charges') {
                return false;
            }

            if (!str_contains((string) $request->header('Authorization')[0], 'Bearer sk_test_unit_secret')) {
                return false;
            }

            $body = $request->data();

            return $body['amount'] === 13.255
                && $body['currency'] === 'KWD'
                && $body['customer']['first_name'] === 'Test Customer'
                && $body['customer']['email'] !== ''
                && $body['source']['id'] === 'src_all'
                && $body['redirect']['url'] === 'https://app.test/callback'
                && $body['post']['url'] === route('api.checkout.webhooks.tap')
                && $body['reference']['order'] === '5'
                && $body['reference']['idempotent'] === 'order-5'
                && $body['metadata']['order_id'] === '5'
                && $body['threeDSecure'] === true;
        });
    }

    /** @test */
    public function it_never_hardcodes_the_charge_currency()
    {
        Http::fake([
            'api.tap.company/v2/charges' => Http::response($this->chargeResponse([
                'currency' => 'SAR',
                'amount' => 100.0,
            ]), 200),
        ]);

        $result = $this->gateway()->createInvoice(
            $this->order(currency: 'SAR', total: 100.0),
            100.0,
            'https://app.test/callback',
            'https://app.test/error',
        );

        $this->assertTrue($result->success);
        $this->assertSame('SAR', $result->currency);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['currency'] === 'SAR' && $body['amount'] === 100.0;
        });
    }

    /** @test */
    public function it_fails_closed_on_an_unsupported_order_currency()
    {
        $result = $this->gateway()->createInvoice(
            $this->order(currency: 'XYZ'),
            13.2549,
            'https://app.test/callback',
            'https://app.test/error',
        );

        $this->assertFalse($result->success);
        $this->assertSame(
            __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => 'XYZ']),
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
    public function it_fails_closed_when_the_provider_rejects_the_charge()
    {
        Http::fake([
            'api.tap.company/v2/charges' => Http::response(['errors' => ['validation error']], 422),
        ]);

        $result = $this->gateway()->createInvoice(
            $this->order(),
            13.2549,
            'https://app.test/callback',
            'https://app.test/error',
        );

        $this->assertFalse($result->success);
        $this->assertSame('Charge request failed (HTTP 422)', $result->errorMessage);
        $this->assertNull($result->rawResponse);
    }

    /** @test */
    public function it_fails_closed_on_a_transport_failure()
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timeout');
        });

        $result = $this->gateway()->createInvoice(
            $this->order(),
            13.2549,
            'https://app.test/callback',
            'https://app.test/error',
        );

        $this->assertFalse($result->success);
        $this->assertSame('Payment gateway error', $result->errorMessage);
    }

    /** @test */
    public function it_fails_closed_when_the_charge_response_lacks_a_redirect()
    {
        Http::fake([
            'api.tap.company/v2/charges' => Http::response($this->chargeResponse(['transaction' => null]), 200),
        ]);

        $result = $this->gateway()->createInvoice(
            $this->order(),
            13.2549,
            'https://app.test/callback',
            'https://app.test/error',
        );

        $this->assertFalse($result->success);
        $this->assertSame('Invalid gateway response', $result->errorMessage);
    }

    /** @test */
    public function it_strips_customer_and_card_material_from_the_stored_response()
    {
        Http::fake([
            'api.tap.company/v2/charges' => Http::response($this->chargeResponse(), 200),
        ]);

        $result = $this->gateway()->createInvoice(
            $this->order(),
            13.2549,
            'https://app.test/callback',
            'https://app.test/error',
        );

        $raw = $result->rawResponse ?? [];

        $this->assertArrayHasKey('id', $raw);
        $this->assertArrayHasKey('redirect_url', $raw);
        $this->assertArrayNotHasKey('customer', $raw);
        $this->assertArrayNotHasKey('card', $raw);
        $this->assertArrayNotHasKey('source', $raw);
        $this->assertArrayNotHasKey('reference', $raw);
        $this->assertStringNotContainsString('Leaked', json_encode($raw));
    }

    /** @test */
    public function it_verifies_a_captured_charge_with_the_provider_amount_and_currency()
    {
        Http::fake([
            'api.tap.company/v2/charges/chg_test123' => Http::response($this->chargeResponse([
                'status' => 'CAPTURED',
            ]), 200),
        ]);

        $result = $this->gateway()->verifyPayment('chg_test123');

        $this->assertTrue($result->success);
        $this->assertSame('paid', $result->status);
        $this->assertSame('chg_test123', $result->gatewayTransactionId);
        $this->assertSame(13.255, $result->amount);
        $this->assertSame('KWD', $result->currency);
    }

    /** @test */
    public function it_treats_every_non_captured_status_as_not_paid()
    {
        // Charges doc: CAPTURED = charged, every other listed status is
        // "Payment failed" — including INITIATED (not yet paid).
        foreach (['INITIATED', 'FAILED', 'DECLINED', 'CANCELLED', 'TIMEDOUT', 'ABANDONED', 'RESTRICTED', 'VOID', 'UNKNOWN'] as $status) {
            Http::fake([
                'api.tap.company/v2/charges/*' => Http::response($this->chargeResponse([
                    'status' => $status,
                ]), 200),
            ]);

            $result = $this->gateway()->verifyPayment('chg_test123');

            $this->assertFalse($result->success, "status {$status} must not complete");
            $this->assertSame('failed', $result->status);
        }
    }

    /** @test */
    public function it_fails_closed_when_retrieve_errors_or_times_out()
    {
        Http::fake([
            'api.tap.company/v2/charges/chg_test123' => Http::response(['errors' => ['not found']], 404),
        ]);

        $result = $this->gateway()->verifyPayment('chg_test123');

        $this->assertFalse($result->success);
        $this->assertSame('Payment verification failed (HTTP 404)', $result->errorMessage);

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timeout');
        });

        $result = $this->gateway()->verifyPayment('chg_test123');

        $this->assertFalse($result->success);
        $this->assertSame('Payment gateway error', $result->errorMessage);
    }

    /** @test */
    public function it_redacts_key_material_from_provider_error_messages()
    {
        Http::fake([
            'api.tap.company/v2/charges' => Http::response([
                'message' => 'Invalid key sk_test_unit_secret supplied',
            ], 401),
        ]);

        $result = $this->gateway()->createInvoice(
            $this->order(),
            13.2549,
            'https://app.test/callback',
            'https://app.test/error',
        );

        $this->assertFalse($result->success);
        $this->assertStringNotContainsString('sk_test_unit_secret', (string) $result->errorMessage);
        $this->assertStringContainsString('[redacted]', (string) $result->errorMessage);
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
        $result = $this->gateway()->refund($this->order(currency: 'XYZ'), 5.0, 'oops');
        $this->assertFalse($result->success);
        $this->assertSame(
            __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => 'XYZ']),
            $result->errorMessage,
        );

        $result = $this->gateway()->refund($this->order(), 0.0, 'oops');
        $this->assertFalse($result->success);
        $this->assertSame('Invalid payment amount', $result->errorMessage);

        Http::assertNothingSent();
    }
}

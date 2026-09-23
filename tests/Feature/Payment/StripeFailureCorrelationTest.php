<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\DTOs\CheckoutTotals;
use App\Services\Currency\CurrencyService;
use App\Services\Gateway\StripeGateway;
use App\Services\Payment\PaymentCurrencyResolver;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Support\Facades\Event;
use App\Events\PaymentFailed;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * MUST-FIX #3: Stripe payment_failed correlation.
 *
 * Transactions store the cs_* session id while failure events carry pi_*.
 * The webhook must resolve the owning session via sessions->all() filtered
 * by payment_intent, then mark failed; unresolvable events ack 200
 * (documented). Rows verified after this fix also carry _payment_intent_id
 * for direct future correlation.
 */
class StripeFailureCorrelationTest extends CurrencyTestCase
{
    private const URL = '/api/v1/general/checkout/webhooks/stripe';

    private const SECRET = 'whsec_test_secret_for_failures';

    protected function setUp(): void
    {
        parent::setUp();

        config(['payment.gateways.stripe.secret_key' => 'sk_test_fake']);
        config(['payment.gateways.stripe.webhook_secret' => self::SECRET]);
        config(['payment.gateways.stripe.supported_currencies' => ['USD', 'EUR', 'KWD']]);
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
            orderData: ['user_id' => $customer->id, 'name' => 'Fail', 'user_phone' => '01000000000', 'user_email' => $customer->email, 'address' => 'x'],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        return $order->fresh();
    }

    private function makePendingTxn(Order $order, string $ref, array $overrides = []): Transaction
    {
        return $order->transactions()->create(array_merge([
            'user_id' => $order->user_id,
            'payment_method' => 'stripe',
            'status' => 'pending',
            'amount' => (float) $order->total_price,
            'currency' => 'USD',
            'gateway_transaction_id' => $ref,
            'invoice_id' => $ref,
        ], $overrides));
    }

    private function stripeHeader(string $payload): string
    {
        $timestamp = time();

        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, self::SECRET);
    }

    private function failedPayload(string $eventId, string $paymentIntentId): string
    {
        return (string) json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => ['id' => $paymentIntentId, 'object' => 'payment_intent']],
        ]);
    }

    private function postStripe(string $payload)
    {
        return $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $this->stripeHeader($payload),
        ], $payload);
    }

    private function bindStripeAdapter(?string $sessionId): void
    {
        $fake = new PiFakeStripeClient($sessionId);
        $adapter = new StripeGateway(app(PaymentCurrencyResolver::class), $fake);

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')->with('stripe')->andReturn($adapter);
        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);
    }

    /** @test */
    public function payment_failed_resolves_session_via_api_and_marks_failed(): void
    {
        Event::fake([PaymentFailed::class]);

        $order = $this->makeUsdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'cs_test_resolve_me');

        // No direct pi correlation on the row: the controller must list
        // sessions filtered by payment_intent to find cs_test_resolve_me.
        $this->bindStripeAdapter('cs_test_resolve_me');

        $response = $this->postStripe($this->failedPayload('evt_pi_1', 'pi_test_123'));

        $response->assertStatus(200);
        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
    }

    /** @test */
    public function payment_failed_uses_persisted_payment_intent_id_directly(): void
    {
        Event::fake([PaymentFailed::class]);

        $order = $this->makeUsdOrder(100.0);
        // Row verified after the fix carries the correlation key: no Stripe
        // API call is needed at all (no factory binding here — any API
        // attempt would throw through the unmocked factory).
        $txn = $this->makePendingTxn($order, 'cs_test_direct', [
            'gateway_response' => ['_payment_intent_id' => 'pi_direct_456'],
        ]);

        $response = $this->postStripe($this->failedPayload('evt_pi_2', 'pi_direct_456'));

        $response->assertStatus(200);
        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
    }

    /** @test */
    public function payment_failed_unresolvable_acks_200_without_state_change(): void
    {
        $order = $this->makeUsdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'cs_test_other');

        // API finds no session for this intent → documented 200/ignored.
        $this->bindStripeAdapter(null);

        $response = $this->postStripe($this->failedPayload('evt_pi_3', 'pi_no_such_session'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'ignored');
        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
    }

    /** @test */
    public function verify_persists_payment_intent_id_for_future_correlation(): void
    {
        $client = new PiFakeStripeClient('cs_unused');
        $client->sessionsFake->retrieveResult = StripeObject::constructFrom([
            'id' => 'cs_test_pi_key',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 10000,
            'currency' => 'usd',
            'payment_intent' => 'pi_persist_me',
        ]);
        $client->intentsFake->retrieveResult = StripeObject::constructFrom([
            'id' => 'pi_persist_me',
            'amount' => 10000,
            'currency' => 'usd',
            'status' => 'succeeded',
        ]);

        $gateway = new StripeGateway(app(PaymentCurrencyResolver::class), $client);
        $result = $gateway->verifyPayment('cs_test_pi_key');

        $this->assertTrue($result->success);
        $this->assertSame('pi_persist_me', $result->rawResponse['_payment_intent_id'] ?? null);
    }
}

// -----------------------------------------------------------------
// Local fakes (unique names — same namespace as the other stripe tests)
// -----------------------------------------------------------------

class PiFakeSessions
{
    public function __construct(private ?string $sessionId) {}

    public mixed $retrieveResult = null;

    public function create($params = null, $opts = null)
    {
        return null;
    }

    public function retrieve($id, $params = null, $opts = null)
    {
        return $this->retrieveResult;
    }

    public function all($params = null, $opts = null)
    {
        // Mirrors stripe-php SessionService::all: a collection whose
        // data holds the sessions matching the payment_intent filter.
        if (($params['payment_intent'] ?? null) && $this->sessionId !== null) {
            return StripeObject::constructFrom(['data' => [['id' => $this->sessionId]]]);
        }

        return StripeObject::constructFrom(['data' => []]);
    }
}

class PiFakeCheckout
{
    public function __construct(public PiFakeSessions $sessions) {}
}

class PiFakeIntents
{
    public mixed $retrieveResult = null;

    public function retrieve($id, $params = null, $opts = null)
    {
        return $this->retrieveResult;
    }
}

class PiFakeRefunds
{
    public function create($params = null, $opts = null)
    {
        return null;
    }
}

class PiFakeStripeClient extends StripeClient
{
    public PiFakeSessions $sessionsFake;

    public PiFakeIntents $intentsFake;

    public function __construct(?string $sessionId)
    {
        $this->sessionsFake = new PiFakeSessions($sessionId);
        $this->intentsFake = new PiFakeIntents();
    }

    public function __get($name)
    {
        return match ($name) {
            'checkout' => new PiFakeCheckout($this->sessionsFake),
            'paymentIntents' => $this->intentsFake,
            'refunds' => new PiFakeRefunds(),
            default => throw new \BadMethodCallException('Unknown service ' . $name),
        };
    }
}

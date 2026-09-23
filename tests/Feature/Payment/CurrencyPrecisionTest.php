<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\CheckoutTotals;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Gateway\PayPalGateway;
use App\Services\Gateway\StripeGateway;
use App\Services\Payment\CurrencyPrecision;
use App\Services\Payment\PaymentCurrencyResolver;
use Illuminate\Support\Facades\Event;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * MUST-FIX #1: 3-decimal currencies (KWD/BHD/OMR/JOD/TND).
 *
 * Covers CurrencyPrecision itself plus every wiring point: order snapshot,
 * Stripe minor units, PayPal decimal-string value, refund millis, and a
 * create→verify→complete mocked end-to-end run at 13.255 KWD.
 */
class CurrencyPrecisionTest extends CurrencyTestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Unit: the exponent table
    // -----------------------------------------------------------------

    /** @test */
    public function decimals_for_three_decimal_currencies(): void
    {
        foreach (['BHD', 'JOD', 'KWD', 'OMR', 'TND', 'kwd', ' kwd '] as $code) {
            $this->assertSame(3, CurrencyPrecision::decimalsFor($code), "decimals for {$code}");
        }

        foreach (['USD', 'EUR', 'SAR', 'AED', 'EGP', 'JPY', 'USD'] as $code) {
            $this->assertSame(2, CurrencyPrecision::decimalsFor($code), "decimals for {$code}");
        }
    }

    /** @test */
    public function round_and_minor_units_follow_the_exponent(): void
    {
        $this->assertSame(13.255, CurrencyPrecision::roundForCurrency(13.255, 'KWD'));
        $this->assertSame(13.26, CurrencyPrecision::roundForCurrency(13.255, 'USD'));

        $this->assertSame(13255, CurrencyPrecision::toMinorUnits(13.255, 'KWD'));
        $this->assertSame(1099, CurrencyPrecision::toMinorUnits(10.99, 'USD'));

        $this->assertEqualsWithDelta(13.255, CurrencyPrecision::fromMinorUnits(13255, 'KWD'), 0.0000001);

        $this->assertSame('13.255', CurrencyPrecision::formatForGateway(13.255, 'KWD'));
        $this->assertSame('10.50', CurrencyPrecision::formatForGateway(10.5, 'USD'));
    }

    // -----------------------------------------------------------------
    // Wiring: Stripe keeps zero-decimal override, delegates the rest
    // -----------------------------------------------------------------

    /** @test */
    public function stripe_minor_units_for_kwd_three_decimals(): void
    {
        // 13.255 KWD → 13255 (was 1326/1325 under fixed x100 / 2dp truncation).
        $this->assertSame(13255, StripeGateway::toMinorUnits(13.255, 'KWD'));
        $this->assertEqualsWithDelta(13.255, StripeGateway::fromMinorUnits(13255, 'KWD'), 0.0000001);

        // Zero-decimal override stays in the gateway, untouched.
        $this->assertSame(500, StripeGateway::toMinorUnits(500, 'JPY'));
        $this->assertSame(1099, StripeGateway::toMinorUnits(10.99, 'USD'));
    }

    /** @test */
    public function stripe_create_invoice_sends_13255_for_13255_kwd(): void
    {
        $this->seedCurrencyData();
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());
        config(['payment.gateways.stripe.supported_currencies' => ['KWD', 'USD']]);

        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => 13.255]);
        $order = app(OrderCreationService::class)->createOrder(
            orderData: ['user_id' => $customer->id, 'name' => 'KWD', 'user_phone' => '01000000000', 'user_email' => $customer->email, 'address' => 'x'],
            cart: $cart,
            checkoutTotals: new CheckoutTotals(13.255, 0, 0, 13.255),
            shippingPrice: 0,
        );

        $this->assertSame(13.255, (float) $order->fresh()->total_price);

        $client = new PrecisionFakeStripeClient();
        $client->sessionsFake->createResult = StripeObject::constructFrom([
            'id' => 'cs_kwd_13255',
            'url' => 'https://checkout.stripe.test/pay/cs_kwd_13255',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'amount_total' => 13255,
            'currency' => 'kwd',
        ]);

        $gateway = new StripeGateway(app(PaymentCurrencyResolver::class), $client);
        $result = $gateway->createInvoice($order->fresh(), 13.255, 'https://shop.test/cb', 'https://shop.test/er');

        $this->assertTrue($result->success);
        $this->assertSame(13255, $client->sessionsFake->lastCreateParams['line_items'][0]['price_data']['unit_amount']);
    }

    // -----------------------------------------------------------------
    // Wiring: PayPal decimal-string value keeps 3dp
    // -----------------------------------------------------------------

    /** @test */
    public function paypal_create_invoice_value_keeps_three_decimals(): void
    {
        $this->seedCurrencyData();
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());
        config(['payment.gateways.paypal.supported_currencies' => ['KWD', 'USD']]);

        $fake = new PrecisionFakePayPalClient();
        $fake->stubs['createOrder'] = [
            'id' => 'PAYPAL-KWD-1',
            'status' => 'CREATED',
            'links' => [['href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-KWD-1', 'rel' => 'approve', 'method' => 'GET']],
        ];

        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => 13.255]);
        $order = app(OrderCreationService::class)->createOrder(
            orderData: ['user_id' => $customer->id, 'name' => 'KWD', 'user_phone' => '01000000000', 'user_email' => $customer->email, 'address' => 'x'],
            cart: $cart,
            checkoutTotals: new CheckoutTotals(13.255, 0, 0, 13.255),
            shippingPrice: 0,
        );

        $gateway = new PayPalGateway(app(PaymentCurrencyResolver::class), fn () => $fake);
        $result = $gateway->createInvoice($order->fresh(), 13.255, 'https://shop.test/cb', 'https://shop.test/er');

        $this->assertTrue($result->success);
        $this->assertSame('13.255', $fake->calls['createOrder'][0]['purchase_units'][0]['amount']['value']);
    }

    // -----------------------------------------------------------------
    // End-to-end: KWD 13.255 create → verify → complete (mocked provider)
    // -----------------------------------------------------------------

    /** @test */
    public function kwd_13255_snapshots_and_completes_end_to_end(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $this->seedCurrencyData();
        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());
        config(['payment.gateways.myfatoorah.api_key' => 'test-key']);

        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => 13.255]);
        $order = app(OrderCreationService::class)->createOrder(
            orderData: ['user_id' => $customer->id, 'name' => 'KWD', 'user_phone' => '01000000000', 'user_email' => $customer->email, 'address' => 'x'],
            cart: $cart,
            checkoutTotals: new CheckoutTotals(13.255, 0, 0, 13.255),
            shippingPrice: 0,
        );

        // Snapshot keeps all three decimals (regression: was 13.25/13.26).
        $this->assertSame(13.255, (float) $order->fresh()->total_price);

        $txn = $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah',
            'status' => 'pending',
            'amount' => (float) $order->fresh()->total_price,
            'currency' => 'KWD',
            'gateway_transaction_id' => 'PAY-KWD-13255',
            'invoice_id' => 'PAY-KWD-13255',
            'gateway_response' => ['_callback_type' => 'mobile'],
        ]);

        $mock = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $mock->shouldReceive('checkInvoice')->andReturn([
            'IsSuccess' => true,
            'Message' => 'Paid',
            'Data' => [
                'InvoiceId' => 'PAY-KWD-13255',
                'InvoiceStatus' => 'Paid',
                'InvoiceValue' => 13.255,
                'DisplayCurrencyIso' => 'KWD',
            ],
        ]);
        $this->app->instance(\App\Services\General\MyfatoraService::class, $mock);

        $response = $this->get('/api/v1/general/checkout/callback?paymentId=PAY-KWD-13255&type=mobile');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
        $this->assertEqualsWithDelta(13.255, (float) $txn->fresh()->amount, 0.0000001);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    /** @test */
    public function refund_millis_use_currency_exponent(): void
    {
        // toMinorUnits is the shared millis primitive the refund service now
        // uses: 10.005 KWD and 10.00 USD map without cross-exponent leakage.
        $this->assertSame(10005, CurrencyPrecision::toMinorUnits(10.005, 'KWD'));
        $this->assertSame(1000, CurrencyPrecision::toMinorUnits(10.00, 'USD'));
        $this->assertEqualsWithDelta(10.005, CurrencyPrecision::fromMinorUnits(10005, 'KWD'), 0.0000001);
    }
}

// -----------------------------------------------------------------
// Local fakes (unique names — same namespace as the other gateway tests)
// -----------------------------------------------------------------

class PrecisionFakeSessions
{
    public ?array $lastCreateParams = null;

    public mixed $createResult = null;

    public function create($params = null, $opts = null)
    {
        $this->lastCreateParams = is_array($params) ? $params : [];

        return $this->createResult;
    }

    public function retrieve($id, $params = null, $opts = null)
    {
        return null;
    }

    public function all($params = null, $opts = null)
    {
        return StripeObject::constructFrom(['data' => []]);
    }
}

class PrecisionFakeCheckout
{
    public function __construct(public PrecisionFakeSessions $sessions) {}
}

class PrecisionFakeIntents
{
    public function retrieve($id, $params = null, $opts = null)
    {
        return null;
    }
}

class PrecisionFakeRefunds
{
    public function create($params = null, $opts = null)
    {
        return null;
    }
}

class PrecisionFakeStripeClient extends StripeClient
{
    public PrecisionFakeSessions $sessionsFake;

    public function __construct()
    {
        $this->sessionsFake = new PrecisionFakeSessions();
    }

    public function __get($name)
    {
        return match ($name) {
            'checkout' => new PrecisionFakeCheckout($this->sessionsFake),
            'paymentIntents' => new PrecisionFakeIntents(),
            'refunds' => new PrecisionFakeRefunds(),
            default => throw new \BadMethodCallException('Unknown service ' . $name),
        };
    }
}

final class PrecisionFakePayPalClient
{
    /** @var array<string, list<mixed>> */
    public array $calls = [];

    /** @var array<string, mixed> */
    public array $stubs = [];

    public function setRequestHeader(string $key, string $value): self
    {
        return $this;
    }

    public function createOrder(array $data): mixed
    {
        $this->calls['createOrder'][] = $data;
        $stub = $this->stubs['createOrder'] ?? null;

        if ($stub instanceof \Throwable) {
            throw $stub;
        }

        return $stub;
    }

    public function showOrderDetails(string $orderId): mixed
    {
        return $this->stubs['showOrderDetails'] ?? null;
    }

    public function capturePaymentOrder(string $orderId, array $data = []): mixed
    {
        return $this->stubs['capturePaymentOrder'] ?? null;
    }

    public function refundCapturedPayment(string $captureId, string $invoiceId, float $amount, string $note): mixed
    {
        return $this->stubs['refundCapturedPayment'] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\DTOs\CheckoutTotals;
use App\Services\Checkout\OrderCreationService;
use App\Services\Gateway\MyFatoorahGateway;
use App\Services\Payment\PaymentCheckoutHandler;
use Illuminate\Http\Request;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;

class GatewayCurrencySupportTest extends CurrencyTestCase
{
    /**
     * Create an order whose effective (and snapshot) currency is USD, which
     * MyFatoorah does not support.
     */
    private function createOrderInUsd(float $subtotal = 100.0): Order
    {
        $this->seedCurrencyData();

        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Unsupported Currency Customer',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 Currency Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);
        $this->assertSame('USD', $order->currency_code);

        return $order;
    }

    /** @test */
    public function supports_currency_accepts_the_configured_codes(): void
    {
        $gateway = app(MyFatoorahGateway::class);

        foreach (config('payment.gateways.myfatoorah.supported_currencies') as $code) {
            $this->assertTrue($gateway->supportsCurrency($code), "Gateway should support $code");
        }
    }

    /** @test */
    public function supports_currency_rejects_unknown_codes(): void
    {
        $gateway = app(MyFatoorahGateway::class);

        $this->assertFalse($gateway->supportsCurrency('USD'));
        $this->assertFalse($gateway->supportsCurrency('XXX'));
    }

    /** @test */
    public function create_invoice_is_blocked_for_an_unsupported_currency(): void
    {
        $order = $this->createOrderInUsd();

        $result = app(MyFatoorahGateway::class)->createInvoice(
            $order,
            (float) $order->total_price,
            'https://example.test/cb',
            'https://example.test/er',
        );

        $this->assertFalse($result->success);
        $this->assertSame(
            __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => 'USD']),
            $result->errorMessage
        );
    }

    /** @test */
    public function refund_is_blocked_for_an_unsupported_currency(): void
    {
        $order = $this->createOrderInUsd();

        $result = app(MyFatoorahGateway::class)->refund($order, 50.0);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('USD', (string) $result->errorMessage);
    }

    /** @test */
    public function online_payment_returns_422_without_a_transaction_for_an_unsupported_currency(): void
    {
        $order = $this->createOrderInUsd();

        $response = app(PaymentCheckoutHandler::class)->handleOnlinePayment(
            Request::create('/api/v1/checkout', 'POST'),
            $order,
            (float) $order->total_price,
            'myfatoorah',
            callbackUrl: 'https://example.test/cb',
            errorUrl: 'https://example.test/er',
        );

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('USD', $response->getContent());

        $this->assertDatabaseCount('transactions', 0);
    }
}
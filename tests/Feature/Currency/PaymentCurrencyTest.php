<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\DTOs\CheckoutTotals;
use App\DTOs\GatewayResult;
use App\Jobs\PaymentReconciliationJob;
use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Gateway\MyFatoorahGateway;
use App\Services\Invoice\InvoiceSnapshotService;
use App\Services\Payment\PaymentGatewayFactory;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;

class PaymentCurrencyTest extends CurrencyTestCase
{
    /**
     * Create an order that is authoritative in KWD (effective + base) while the catalog is USD.
     */
    private function createOrderInKwd(float $subtotal = 100.0): Order
    {
        $this->seedCurrencyData();

        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());

        $customer = $this->createCustomerWithCurrencyPreference('KWD');
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Currency Customer',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 Currency Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);

        return $order;
    }

    private function createPaidTransaction(Order $order, string $currency, float $amount, string $gatewayId): void
    {
        $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah',
            'status' => 'paid',
            'amount' => $amount,
            'currency' => $currency,
            'gateway_transaction_id' => $gatewayId,
            'invoice_id' => 42,
        ]);
    }

/** @test */
    public function myfatoorah_invoice_uses_the_orders_currency(): void
    {
        $order = $this->createOrderInKwd();

        $displayCurrency = null;

        $mock = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $mock->shouldReceive('createInvoice')
            ->once()
            ->with(\Mockery::on(function (array $data) use (&$displayCurrency) {
                $displayCurrency = $data['DisplayCurrencyIso'] ?? null;

                return true;
            }))
            ->andReturn([
                'Data' => [
                    'InvoiceURL' => 'https://example.test/pay',
                    'InvoiceId' => 123,
                ],
            ]);
        $this->app->instance(\App\Services\General\MyfatoraService::class, $mock);

        $gateway = app(MyFatoorahGateway::class);

        $result = $gateway->createInvoice($order, (float) $order->total_price, 'https://example.test/cb', 'https://example.test/er');

        $this->assertTrue($result->success);
        $this->assertSame('KWD', $displayCurrency);
    }

/** @test */
    public function myfatoorah_refund_uses_the_orders_currency(): void
    {
        $order = $this->createOrderInKwd();
        $this->createPaidTransaction($order, 'KWD', (float) $order->total_price, 'PAY-REFUND-1');

        $mock = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $mock->shouldReceive('makeRefund')
            ->once()
            ->andReturn([
                'Data' => [
                    'RefundId' => 7,
                    'RefundStatus' => 'Refunded',
                ],
            ]);
        $this->app->instance(\App\Services\General\MyfatoraService::class, $mock);

        $gateway = app(MyFatoorahGateway::class);

        $result = $gateway->refund($order, (float) $order->total_price);

        $this->assertTrue($result->success);
        $this->assertSame('KWD', $result->currency);
    }

/** @test */
    public function payment_reconciliation_compares_currency_in_the_orders_currency(): void
    {
        $order = $this->createOrderInKwd();
        $this->createPaidTransaction($order, 'KWD', (float) $order->total_price, 'PAY-RECON-1');

        $gatewayMock = \Mockery::mock(\App\Services\Payment\Contracts\PaymentGatewayContract::class);
        $gatewayMock->shouldReceive('verifyPayment')
            ->with('PAY-RECON-1')
            ->once()
            ->andReturn(new GatewayResult(
                success: true,
                gatewayTransactionId: 'PAY-RECON-1',
                amount: (float) $order->total_price,
                currency: 'USD',
                status: 'paid',
            ));
        $this->app->instance(\App\Services\Gateway\MyFatoorahGateway::class, $gatewayMock);

        (new PaymentReconciliationJob())->handle(app(PaymentGatewayFactory::class));

        $this->assertDatabaseHas('payment_reconciliation_results', [
            'order_id' => $order->id,
            'mismatch_type' => 'currency',
            'expected_value' => 'KWD',
            'actual_value' => 'USD',
        ]);

        $this->assertDatabaseMissing('payment_reconciliation_results', [
            'order_id' => $order->id,
            'mismatch_type' => 'amount',
        ]);
    }

/** @test */
    public function invoice_snapshot_pricing_currency_uses_the_orders_currency(): void
    {
        $order = $this->createOrderInKwd();
        $this->createPaidTransaction($order, 'KWD', (float) $order->total_price, 'PAY-INV-1');

        $snapshot = app(InvoiceSnapshotService::class)->buildFullSnapshot($order->fresh()->load('transactions'));

        $this->assertSame('KWD', $snapshot['pricing_breakdown']['currency']);
    }

    /** @test */
    public function invoice_snapshot_falls_back_to_the_default_currency_for_legacy_orders(): void
    {
        $this->seedCurrencyData();

        $customer = $this->createCustomer();
        $order = Order::create([
            'user_id' => $customer->id,
            'name' => 'Legacy Customer',
            'user_phone' => '01000000000',
            'user_email' => $customer->email,
            'address' => ['street' => 'Test St'],
            'price' => 50,
            'total_price' => 50,
            'status' => 'pending',
        ]);

        $order->transactions()->create([
            'user_id' => $customer->id,
            'payment_method' => 'cod',
            'status' => 'paid',
            'amount' => 50,
            'invoice_id' => 42,
        ]);

        $snapshot = app(InvoiceSnapshotService::class)->buildFullSnapshot($order->fresh()->load('transactions'));

        $this->assertSame($order->currency_code, null);
        $this->assertSame($order->base_currency_code, null);
        $this->assertSame('EGP', $snapshot['pricing_breakdown']['currency']);
    }
}
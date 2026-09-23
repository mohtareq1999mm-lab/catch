<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\CheckoutTotals;
use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Gateway\MyFatoorahGateway;
use App\Services\Payment\PaymentCheckoutHandler;
use App\Services\Payment\PaymentCurrencyResolver;
use Illuminate\Http\Request;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Tests\Feature\Currency\CurrencyTestCase;

class CatalogCurrencyAuthorityTest extends CurrencyTestCase
{
    private function makeOrderForCustomer(object $customer, float $subtotal = 100.0): Order
    {
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Catalog Authority Customer',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 Catalog Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);

        return $order;
    }

    /** @test */
    public function checkout_handler_and_gateway_invoice_use_catalog_currency_despite_user_preference(): void
    {
        $this->seedCurrencyData();

        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());

        // Selection stays enabled (seed default) so the user preference resolves
        // to USD while the catalog authority remains KWD.
        $customer = $this->createCustomerWithCurrencyPreference('USD');

        $this->assertSame('KWD', app(CurrencyService::class)->getCatalogCode());
        $this->assertSame('USD', app(CurrencyService::class)->getEffectiveCode());

        $order = $this->makeOrderForCustomer($customer);

        // Order currency is ALWAYS the catalog currency, never the effective code.
        $this->assertSame('KWD', $order->currency_code);
        $this->assertSame('KWD', $order->catalog_currency_code);

        // Gateway invoice uses the catalog currency.
        $displayCurrency = null;
        $mock = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $mock->shouldReceive('createInvoice')
            ->once()
            ->with(\Mockery::on(function (array $data) use (&$displayCurrency) {
                $displayCurrency = $data['DisplayCurrencyIso'] ?? null;

                return true;
            }))
            ->andReturn(['Data' => ['InvoiceURL' => 'https://example.test/pay', 'InvoiceId' => 123]]);
        $this->app->instance(\App\Services\General\MyfatoraService::class, $mock);

        $gateway = app(MyFatoorahGateway::class);
        $result = $gateway->createInvoice($order, (float) $order->total_price, 'https://example.test/cb', 'https://example.test/er');

        $this->assertTrue($result->success);
        $this->assertSame('KWD', $displayCurrency);
        $this->assertSame('KWD', $result->currency);

        // Checkout handler uses the catalog currency for the gate + transaction.
        $mock2 = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $mock2->shouldReceive('createInvoice')
            ->once()
            ->with(\Mockery::on(function (array $data) use (&$displayCurrency) {
                $displayCurrency = $data['DisplayCurrencyIso'] ?? null;

                return true;
            }))
            ->andReturn(['Data' => ['InvoiceURL' => 'https://example.test/pay', 'InvoiceId' => 456]]);
        $this->app->instance(\App\Services\General\MyfatoraService::class, $mock2);

        $request = Request::create('/api/v1/checkout', 'POST');
        $request->setUserResolver(fn () => $customer);

        $response = app(PaymentCheckoutHandler::class)->handleOnlinePayment(
            $request,
            $order->fresh(),
            (float) $order->total_price,
            'myfatoorah',
            'https://example.test/cb',
            'https://example.test/er',
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('KWD', $displayCurrency);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'currency' => 'KWD',
        ]);
    }

    /** @test */
    public function catalog_switch_only_affects_new_orders(): void
    {
        $this->seedCurrencyData();

        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());
        $customerOne = $this->createCustomer();
        $oldOrder = $this->makeOrderForCustomer($customerOne);
        $this->assertSame('KWD', $oldOrder->currency_code);

        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'SAR')->firstOrFail());
        $customerTwo = $this->createCustomer();
        $newOrder = $this->makeOrderForCustomer($customerTwo);
        $this->assertSame('SAR', $newOrder->currency_code);

        $this->assertSame('KWD', $oldOrder->fresh()->currency_code);
        $this->assertSame('KWD', $oldOrder->fresh()->catalog_currency_code);
        $this->assertSame('SAR', $newOrder->fresh()->catalog_currency_code);
    }

    /** @test */
    public function currency_mismatch_is_rejected_and_resolver_falls_back_safely(): void
    {
        $this->seedCurrencyData();

        // Default seed catalog is USD, which MyFatoorah does not support.
        $customer = $this->createCustomer();
        $order = $this->makeOrderForCustomer($customer);
        $this->assertSame('USD', $order->currency_code);

        $request = Request::create('/api/v1/checkout', 'POST');
        $request->setUserResolver(fn () => $customer);

        $response = app(PaymentCheckoutHandler::class)->handleOnlinePayment(
            $request,
            $order->fresh(),
            (float) $order->total_price,
            'myfatoorah',
            'https://example.test/cb',
            'https://example.test/er',
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('USD', (string) $response->getContent());
        $this->assertDatabaseCount('transactions', 0);

        $resolver = app(PaymentCurrencyResolver::class);

        // Catalog snapshot wins over a stale currency_code.
        $stale = new Order(['catalog_currency_code' => ' sar ', 'currency_code' => 'KWD']);
        $this->assertSame('SAR', $resolver->forOrder($stale));

        // Legacy rows without any snapshot fall back to the catalog code.
        $legacy = new Order(['catalog_currency_code' => null, 'currency_code' => null]);
        $this->assertSame($resolver->current(), $resolver->forOrder($legacy));

        // Resolver authority is the catalog code.
        $this->assertSame(app(CurrencyService::class)->getCatalogCode(), $resolver->current());
    }
}

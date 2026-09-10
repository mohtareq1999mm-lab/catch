<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\DTOs\CheckoutTotals;
use App\Http\Resources\Order\OrderResource;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use Illuminate\Support\Facades\Event;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;

class OrderCurrencyTest extends CurrencyTestCase
{
    private function makeCart(): Cart
    {
        return Cart::create([
            'user_id' => $this->createCustomer()->id,
            'status' => 'active',
            'total_price' => 100.0,
        ]);
    }

    private function makeTotals(float $subtotal): CheckoutTotals
    {
        return new CheckoutTotals(
            subtotal: $subtotal,
            promotionDiscount: 0,
            couponDiscount: 0,
            finalTotal: $subtotal,
        );
    }

    private function createOrder(OrderCreationService $service, Cart $cart, CheckoutTotals $totals): Order
    {
$order = $service->createOrder(
            orderData: [
                'user_id' => $cart->user_id,
                'name' => 'Currency Customer',
                'user_phone' => '01000000000',
                'user_email' => 'currency@example.com',
                'address' => '123 Currency Street',
            ],
            cart: $cart,
            checkoutTotals: $totals,
            shippingPrice: 0,
        );

        $this->assertNotNull($order);

        return $order;
    }

    /** @test */
    public function order_snapshots_currency_when_catalog_differs_from_base(): void
    {
        Event::fake();
        $this->seedCurrencyData();

        app(CurrencyService::class)->setBaseCurrency(\App\Models\Currency::query()->where('code', 'KWD')->firstOrFail());

        $cart = $this->makeCart();
        $order = $this->createOrder(app(OrderCreationService::class), $cart, $this->makeTotals(100.0));

        $this->assertSame('USD', $order->currency_code);
        $this->assertSame('KWD', $order->base_currency_code);
        $this->assertSame('USD', $order->catalog_currency_code);
        $this->assertSame('0.221000', $order->currency_rate);
        $this->assertEquals(now()->toDateString(), $order->currency_rate_date->toDateString());
        $this->assertSame(22.1, $order->converted_total_price);
        $this->assertSame(100.0, $order->total_price);
    }

    /** @test */
    public function order_preserves_total_when_catalog_equals_base(): void
    {
        Event::fake();
        $this->seedCurrencyData();

        $cart = $this->makeCart();
        $order = $this->createOrder(app(OrderCreationService::class), $cart, $this->makeTotals(100.0));

        $this->assertSame('USD', $order->currency_code);
        $this->assertSame('USD', $order->base_currency_code);
        $this->assertSame('USD', $order->catalog_currency_code);
        $this->assertSame('1', $order->currency_rate);
        $this->assertSame(100.0, $order->converted_total_price);
        $this->assertSame(100.0, $order->total_price);
    }

    /** @test */
    public function order_snapshot_refreshes_when_base_currency_changes(): void
    {
        Event::fake();
        $kwd = $this->seedCurrencyData()['KWD'];

        $service = app(OrderCreationService::class);
        $cart = $this->makeCart();

        $order = $this->createOrder($service, $cart, $this->makeTotals(100.0));
        $this->assertSame('USD', $order->base_currency_code);
        $this->assertSame('USD', $order->catalog_currency_code);

        app(CurrencyService::class)->setBaseCurrency($kwd);

        $updated = $service->updateOrder(
            order: $order,
            orderData: ['user_id' => $order->user_id],
            cart: $cart,
            checkoutTotals: $this->makeTotals(200.0),
            shippingPrice: 0,
        );

        $this->assertSame('USD', $updated->currency_code);
        $this->assertSame('KWD', $updated->base_currency_code);
        $this->assertSame('USD', $updated->catalog_currency_code);
        $this->assertSame(44.2, $updated->converted_total_price);
        $this->assertSame(200.0, $updated->total_price);
    }

    /** @test */
    public function order_with_zero_total_still_records_snapshot(): void
    {
        Event::fake();
        $this->seedCurrencyData();

        app(CurrencyService::class)->setBaseCurrency(\App\Models\Currency::query()->where('code', 'KWD')->firstOrFail());

        $cart = $this->makeCart();
        $order = $this->createOrder(app(OrderCreationService::class), $cart, $this->makeTotals(0.0));

        $this->assertSame('USD', $order->currency_code);
        $this->assertSame('KWD', $order->base_currency_code);
        $this->assertSame('USD', $order->catalog_currency_code);
        $this->assertSame('0.221000', $order->currency_rate);
        $this->assertSame(0.0, $order->converted_total_price);
        $this->assertSame(0.0, $order->total_price);
    }

    /** @test */
    public function order_resource_exposes_the_currency_snapshot(): void
    {
        Event::fake();
        $this->seedCurrencyData();

        app(CurrencyService::class)->setBaseCurrency(\App\Models\Currency::query()->where('code', 'KWD')->firstOrFail());

        $cart = $this->makeCart();
        $order = $this->createOrder(app(OrderCreationService::class), $cart, $this->makeTotals(100.0));

        $data = OrderResource::make($order)->toArray(request());

        $this->assertSame('USD', $data['currency']);
        $this->assertSame('KWD', $data['base_currency']);
        $this->assertSame('USD', $data['catalog_currency']);
        $this->assertSame('0.221000', $data['exchange_rate']);
        $this->assertSame(22.1, $data['converted_total']);
        $this->assertSame(100.0, $data['total']);
    }
}

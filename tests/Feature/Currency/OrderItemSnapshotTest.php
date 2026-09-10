<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\DTOs\CheckoutTotals;
use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use Illuminate\Support\Str;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;

class OrderItemSnapshotTest extends CurrencyTestCase
{
    private function makeProduct(float $price = 100.0): Product
    {
        return Product::create([
            'name' => 'Snapshot Product',
            'slug' => 'snapshot-product-' . Str::uuid(),
            'price' => $price,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'reserved_quantity' => 0,
            'has_discount' => false,
            'has_flash_sale' => false,
        ]);
    }

    private function makeCartWithItem(User $user, Product $product, int $quantity = 1, float $unitPrice = 100.0): Cart
    {
        $cart = Cart::create([
            'user_id' => $user->id,
            'status' => 'active',
            'total_price' => $unitPrice * $quantity,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $unitPrice,
            'total_price' => $unitPrice * $quantity,
        ]);

        $cart->load(['items', 'items.product', 'items.product.flash_sales' => fn($q) => $q->valid()]);

        return $cart;
    }

    private function createOrder(OrderCreationService $service, User $user, Cart $cart, float $subtotal): Order
    {
        $order = $service->createOrder(
            orderData: [
                'user_id' => $user->id,
                'name' => 'Item Customer',
                'user_phone' => '01000000000',
                'user_email' => $user->email,
                'address' => '1 Snapshot Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);

        return $order;
    }

    /** @test */
    public function order_items_store_both_catalog_and_effective_amounts(): void
    {
        $this->seedCurrencyData();

        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());

        $user = $this->createCustomerWithCurrencyPreference('KWD');
        $cart = $this->makeCartWithItem($user, $this->makeProduct());

        $service = app(OrderCreationService::class);
        $order = $this->createOrder($service, $user, $cart, 100.0);

        $this->assertTrue($service->createOrderItems($order, $cart));

        $item = $order->orderItems()->first();

        $this->assertNotNull($item);
        $this->assertSame('KWD', $item->currency_code);
        $this->assertSame('USD', $item->catalog_currency_code);
        $this->assertSame(100.0, (float) $item->catalog_price);
        $this->assertSame(100.0, (float) $item->catalog_total_price);
        $this->assertSame(22.1, (float) $item->product_price);
        $this->assertSame(22.1, (float) $item->product_total_price);
    }

    /** @test */
    public function order_currency_never_leaks_into_the_catalog_amounts(): void
    {
        $this->seedCurrencyData();

        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());

        $user = $this->createCustomerWithCurrencyPreference('KWD');
        $cart = $this->makeCartWithItem($user, $this->makeProduct());

        $service = app(OrderCreationService::class);
        $order = $this->createOrder($service, $user, $cart, 100.0);

        $service->createOrderItems($order, $cart);

        $item = $order->orderItems()->first();

        $this->assertSame('USD', $item->catalog_currency_code);
        $this->assertSame(100.0, (float) $item->catalog_price);
        $this->assertSame(100.0, (float) $item->catalog_total_price);
    }

    /** @test */
    public function historical_orders_keep_their_snapshot_when_rates_change(): void
    {
        $this->seedCurrencyData();

        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());

        $user = $this->createCustomer();
        $cart = $this->makeCartWithItem($user, $this->makeProduct());

        $order = $this->createOrder(app(OrderCreationService::class), $user, $cart, 100.0);

        $this->assertSame('USD', $order->currency_code);
        $this->assertSame(100.0, $order->total_price);
        $this->assertSame('0.221000', $order->currency_rate);

        \App\Models\CurrencyRate::query()
            ->whereHas('currency', fn($query) => $query->where('code', 'KWD'))
            ->whereDate('effective_date', now()->toDateString())
            ->update(['exchange_rate' => '0.3000000000']);

        app()->forgetInstance(CurrencyService::class);

        $persisted = Order::query()->findOrFail($order->id);

$this->assertSame('USD', $persisted->currency_code);
        $this->assertSame(100.0, (float) $persisted->total_price);
        $this->assertEqualsWithDelta(0.221, (float) $persisted->currency_rate, 0.0000001, 'Snapshot currency rate must not be recomputed');
    }

    /** @test */
    public function checkout_fails_when_the_effective_currency_has_no_rate(): void
    {
        $this->seedCurrencyData();

        $this->createCurrency('EUR');

        $user = $this->createCustomerWithCurrencyPreference('EUR');
        $cart = $this->makeCartWithItem($user, $this->makeProduct());

        $service = app(OrderCreationService::class);

        try {
            $this->createOrder($service, $user, $cart, 100.0);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('EUR', $e->getMessage());
        }

        $this->assertDatabaseCount('orders', 0);
    }
}
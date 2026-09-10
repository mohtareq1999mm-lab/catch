<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CheckoutTotals;
use App\Services\Checkout\OrderCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Marvel\Enums\ShippingMethod;
use Tests\TestCase;

class PendingOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        if (!Settings::exists()) {
            Settings::create([
                'site_name' => 'Test Site',
                'options' => [],
                'minimum_order_amount' => 0,
            ]);
        }

        $this->user = User::factory()->create();

        $this->product = Product::create([
            'name' => 'Lifecycle Product',
            'slug' => 'lifecycle-product-' . Str::uuid(),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'stock_quantity' => 10,
            'reserved_quantity' => 0,
            'in_stock' => true,
            'status' => true,
        ]);
    }

    private function makeCart(array $items, bool $fresh = false): Cart
    {
        if ($fresh) {
            $old = Cart::where('user_id', $this->user->id)->where('status', 'active')->first();
            if ($old) {
                $old->items()->delete();
                $old->delete();
            }
        }

        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => collect($items)->sum(fn($i) => $i['price'] * $i['quantity']),
        ]);

        foreach ($items as $item) {
            CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $item['product_id'] ?? $this->product->id,
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'total_price' => $item['price'] * $item['quantity'],
                'shipping_method' => $item['shipping_method'] ?? ShippingMethod::SCHEDULED,
            ]);
        }

        return $cart->load(['items', 'items.product']);
    }

    private function makeTotals(Cart $cart): CheckoutTotals
    {
        $subtotal = (float) $cart->items->sum(fn($i) => $i->total_price);

        return new CheckoutTotals(
            subtotal: $subtotal,
            promotionDiscount: 0,
            couponDiscount: 0,
            finalTotal: $subtotal,
        );
    }

    /** @test */
    public function first_checkout_creates_pending_order(): void
    {
        Event::fake();

        $service = app(OrderCreationService::class);
        $cart = $this->makeCart([['quantity' => 1, 'price' => 100.00]]);
        $totals = $this->makeTotals($cart);

        $order = $service->createOrder(
            orderData: ['user_id' => $this->user->id, 'name' => 'Test User', 'user_phone' => '01000000001', 'user_email' => $this->user->email, 'address' => '123 Street'],
            cart: $cart,
            checkoutTotals: $totals,
            shippingPrice: 0,
        );

        $this->assertNotNull($order);
        $this->assertEquals('pending', $order->status);
        $this->assertEquals(100.00, $order->price);
        $this->assertEquals(100.00, $order->total_price);
    }

    /** @test */
    public function find_pending_order_returns_null_after_completion(): void
    {
        $service = app(OrderCreationService::class);
        $cart = $this->makeCart([['quantity' => 1, 'price' => 100.00]]);
        $totals = $this->makeTotals($cart);

        $order = $service->createOrder(
            orderData: ['user_id' => $this->user->id, 'name' => 'Test User', 'user_phone' => '01000000001', 'user_email' => $this->user->email, 'address' => '123 Street'],
            cart: $cart,
            checkoutTotals: $totals,
            shippingPrice: 0,
        );

        $this->assertNotNull($service->findPendingOrderForUser($this->user->id));

        $order->update(['status' => 'completed']);

        $this->assertNull($service->findPendingOrderForUser($this->user->id));
    }

    /** @test */
    public function second_checkout_updates_existing_pending_order(): void
    {
        $service = app(OrderCreationService::class);
        $cart = $this->makeCart([['quantity' => 1, 'price' => 100.00]]);
        $totals = $this->makeTotals($cart);

        $order = $service->createOrder(
            orderData: ['user_id' => $this->user->id, 'name' => 'Test User', 'user_phone' => '01000000001', 'user_email' => $this->user->email, 'address' => '123 Street'],
            cart: $cart,
            checkoutTotals: $totals,
            shippingPrice: 0,
        );

        $this->assertEquals(100.00, $order->total_price);

        $cart2 = $this->makeCart([['quantity' => 3, 'price' => 100.00]], fresh: true);
        $totals2 = $this->makeTotals($cart2);

        $updated = $service->updateOrder(
            order: $order,
            orderData: ['user_id' => $this->user->id],
            cart: $cart2,
            checkoutTotals: $totals2,
            shippingPrice: 0,
        );

        $this->assertEquals(300.00, $updated->total_price);
        $this->assertEquals(1, Order::count(), 'must not create duplicate order');
    }

    /** @test */
    public function sync_order_items_replaces_existing_items(): void
    {
        $service = app(OrderCreationService::class);
        $cart = $this->makeCart([['quantity' => 1, 'price' => 100.00]]);
        $totals = $this->makeTotals($cart);

        $order = $service->createOrder(
            orderData: ['user_id' => $this->user->id, 'name' => 'Test User', 'user_phone' => '01000000001', 'user_email' => $this->user->email, 'address' => '123 Street'],
            cart: $cart,
            checkoutTotals: $totals,
            shippingPrice: 0,
        );

        $service->createOrderItems($order, $cart);
        $this->assertEquals(1, $order->fresh()->orderItems()->count());

        $cart2 = $this->makeCart([
            ['quantity' => 2, 'price' => 50.00],
            ['quantity' => 1, 'price' => 200.00],
        ], fresh: true);
        $totals2 = $this->makeTotals($cart2);

        $updated = $service->updateOrder(
            order: $order,
            orderData: ['user_id' => $this->user->id],
            cart: $cart2,
            checkoutTotals: $totals2,
            shippingPrice: 0,
        );

        $service->syncOrderItems($updated, $cart2);
        $items = $updated->fresh()->orderItems;

        $this->assertCount(2, $items);
    }

    /** @test */
    public function pending_order_does_not_block_new_order_after_payment(): void
    {
        $service = app(OrderCreationService::class);

        $cart1 = $this->makeCart([['quantity' => 1, 'price' => 100.00]]);
        $totals1 = $this->makeTotals($cart1);
        $order1 = $service->createOrder(
            orderData: ['user_id' => $this->user->id, 'name' => 'Test User', 'user_phone' => '01000000001', 'user_email' => $this->user->email, 'address' => '123 Street'],
            cart: $cart1,
            checkoutTotals: $totals1,
            shippingPrice: 0,
        );
        $service->createOrderItems($order1, $cart1);
        $order1->update(['status' => 'completed']);
        $this->assertNull($service->findPendingOrderForUser($this->user->id));

        $cart2 = $this->makeCart([['quantity' => 2, 'price' => 75.00]], fresh: true);
        $totals2 = $this->makeTotals($cart2);
        $order2 = $service->createOrder(
            orderData: ['user_id' => $this->user->id, 'name' => 'Test User', 'user_phone' => '01000000001', 'user_email' => $this->user->email, 'address' => '123 Street'],
            cart: $cart2,
            checkoutTotals: $totals2,
            shippingPrice: 0,
        );
        $service->createOrderItems($order2, $cart2);

        $this->assertEquals(2, Order::count());
        $this->assertNotEquals($order1->id, $order2->id);
        $this->assertEquals(150.00, $order2->total_price);
    }

    /** @test */
    public function cancelled_pending_order_removes_pending_status(): void
    {
        $service = app(OrderCreationService::class);
        $cart = $this->makeCart([['quantity' => 1, 'price' => 100.00]]);
        $totals = $this->makeTotals($cart);

        $order = $service->createOrder(
            orderData: ['user_id' => $this->user->id, 'name' => 'Test User', 'user_phone' => '01000000001', 'user_email' => $this->user->email, 'address' => '123 Street'],
            cart: $cart,
            checkoutTotals: $totals,
            shippingPrice: 0,
        );

        $this->assertNotNull($service->findPendingOrderForUser($this->user->id));

        $order->update(['status' => 'cancelled']);

        $this->assertNull($service->findPendingOrderForUser($this->user->id));

        $cart2 = $this->makeCart([['quantity' => 1, 'price' => 200.00]], fresh: true);
        $totals2 = $this->makeTotals($cart2);
        $order2 = $service->createOrder(
            orderData: ['user_id' => $this->user->id, 'name' => 'Test User', 'user_phone' => '01000000001', 'user_email' => $this->user->email, 'address' => '123 Street'],
            cart: $cart2,
            checkoutTotals: $totals2,
            shippingPrice: 0,
        );

        $this->assertEquals(2, Order::count());
        $this->assertEquals(200.00, $order2->total_price);
    }
}

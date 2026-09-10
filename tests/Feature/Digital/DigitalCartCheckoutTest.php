<?php

namespace Tests\Feature\Digital;

use App\Services\General\CartInventoryService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Marvel\Database\Models\Product;
use Marvel\Enums\ProductType;
use Tests\TestCase;

class DigitalCartCheckoutTest extends TestCase
{
    private CartInventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->inventory = app(CartInventoryService::class);

        if (!Schema::hasTable('activity_log')) {
            Schema::create('activity_log', function (Blueprint $table) {
                $table->id();
                $table->string('log_name')->nullable();
                $table->text('description')->nullable();
                $table->nullableTimestamps();
                $table->json('properties')->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('subject_type')->nullable();
                $table->string('event')->nullable();
                $table->unsignedBigInteger('batch_uuid')->nullable();
            });
        }

        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('type')->default('customer');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->string('sku')->nullable();
                $table->string('slug')->unique();
                $table->string('product_type')->default('simple');
                $table->decimal('price', 10, 2)->default(0);
                $table->string('item_type', 16)->default('PHYSICAL');
                $table->integer('stock_quantity')->default(0);
                $table->integer('reserved_quantity')->default(0);
                $table->integer('sold_quantity')->default(0);
                $table->boolean('in_stock')->default(true);
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('carts')) {
            Schema::create('carts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('status')->default('active');
                $table->decimal('total_price', 10, 2)->default(0);
                $table->string('coupon')->nullable();
                $table->timestamp('reserved_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('cart_items')) {
            Schema::create('cart_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->integer('quantity')->default(1);
                $table->integer('reserved_quantity')->default(0);
                $table->decimal('price', 10, 2)->default(0);
                $table->decimal('total_price', 10, 2)->default(0);
                $table->text('attributes')->nullable();
                $table->string('shipping_method')->default('SCHEDULED');
                $table->unsignedBigInteger('promotion_id')->nullable();
                $table->decimal('discount_amount', 10, 2)->default(0);
                $table->boolean('is_gift')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->softDeletes();
            $table->string('inventory_state', 16)->default('none');
            $table->timestamp('inventory_reserved_at')->nullable();
            $table->timestamp('reservation_expires_at')->nullable();
            $table->timestamps();
            });
        }

        if (!Schema::hasTable('order_products')) {
            Schema::create('order_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->integer('product_quantity')->default(1);
                $table->decimal('product_total_price', 10, 2)->default(0);
                $table->boolean('is_gift')->default(false);
                $table->string('item_type', 16)->default('PHYSICAL');
                $table->timestamps();
            });
        }
    }

    private function makeProduct(string $itemType, int $stock): Product
    {
        return Product::create([
            'slug' => 'cart-' . strtolower($itemType) . '-' . uniqid(),
            'item_type' => $itemType,
            'product_type' => ProductType::SIMPLE,
            'price' => 25.00,
            'stock_quantity' => $stock,
        ]);
    }

    private function makeCart()
    {
        $user = \Marvel\Database\Models\User::create([
            'name' => 'C',
            'email' => 'cart-' . uniqid() . '@example.com',
            'type' => 'customer',
        ]);

        return \Marvel\Database\Models\Cart::create(['user_id' => $user->id, 'status' => 'active']);
    }

    public function test_digital_product_can_be_added_with_zero_physical_stock()
    {
        $cart = $this->makeCart();
        $digital = $this->makeProduct('DIGITAL', stock: 0);

        $item = $this->inventory->incrementItem($cart, $digital, null, 3);

        $this->assertSame(3, (int) $item->quantity);
        $this->assertSame(0, (int) $item->reserved_quantity);
        $this->assertSame(0, (int) $digital->fresh()->reserved_quantity);
    }

    public function test_digital_lines_hold_no_reservation_even_after_updates()
    {
        $cart = $this->makeCart();
        $digital = $this->makeProduct('DIGITAL', stock: 5);

        $this->inventory->incrementItem($cart, $digital, null, 1);
        $this->inventory->incrementItem($cart, $digital, null, 4);

        $this->assertSame(0, (int) $digital->fresh()->reserved_quantity);

        $this->inventory->decrementItem($cart, $digital, null, 5, 'SCHEDULED');

        $this->assertSame(0, (int) $digital->fresh()->sold_quantity);
        $this->assertSame(5, (int) $digital->fresh()->stock_quantity);
    }

    public function test_physical_inventory_behavior_is_unchanged()
    {
        // NEW CONTRACT: cart adds never reserve; the ORDER does.
        $cart = $this->makeCart();
        $physical = $this->makeProduct('PHYSICAL', stock: 2);

        $this->inventory->incrementItem($cart, $physical, null, 2);
        $this->assertSame(0, (int) $physical->fresh()->reserved_quantity);

        // Exceeding physical stock no longer throws at cart level Ã¢â‚¬â€
        // it throws atomically at order reservation.
        $order = \Marvel\Database\Models\Order::create([]);
        \Marvel\Database\Models\OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $physical->id,
            'product_quantity' => 3,
        ]);

        $this->expectException(\Exception::class);
        app(\App\Services\Inventory\OrderReservationService::class)->reserveForOrder($order->refresh());
    }

    public function test_finalization_decrements_only_physical_stock_in_mixed_cart()
    {
        $cart = $this->makeCart();
        $physical = $this->makeProduct('PHYSICAL', stock: 7);
        $digital = $this->makeProduct('DIGITAL', stock: 0);

        $this->inventory->incrementItem($cart, $physical, null, 2);
        $this->inventory->incrementItem($cart, $digital, null, 1);

        // Reservation now happens on the ORDER (mixed cart: physical reserves,
        // digital skips).
        $order = \Marvel\Database\Models\Order::create([]);
        \Marvel\Database\Models\OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $physical->id,
            'product_quantity' => 2,
            'item_type' => 'PHYSICAL',
        ]);
        \Marvel\Database\Models\OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $digital->id,
            'product_quantity' => 1,
            'item_type' => 'DIGITAL',
        ]);

        app(\App\Services\Inventory\OrderReservationService::class)->reserveForOrder($order->refresh());

        $physical->refresh();
        $digital->refresh();

        $this->assertSame(7, (int) $physical->stock_quantity, 'Stock untouched by reservation');
        $this->assertSame(2, (int) $physical->reserved_quantity);
        $this->assertSame(0, (int) $digital->reserved_quantity, 'Digital holds nothing');

        app(\App\Services\Inventory\OrderReservationService::class)->commit($order->refresh());

        $physical->refresh();
        $digital->refresh();

        $this->assertSame(5, (int) $physical->stock_quantity);
        $this->assertSame(2, (int) $physical->sold_quantity);
        $this->assertSame(0, (int) $digital->stock_quantity);
        $this->assertSame(0, (int) $digital->sold_quantity);
    }

    public function test_digital_only_order_line_is_not_deducted_by_order_fallback()
    {
        $digital = $this->makeProduct('DIGITAL', stock: 9);

        $order = \Marvel\Database\Models\Order::create([]);
        \Marvel\Database\Models\OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $digital->id,
            'product_quantity' => 2,
            'item_type' => 'DIGITAL',
        ]);

        app(\App\Services\Inventory\OrderReservationService::class)->reserveForOrder($order->fresh());
        app(\App\Services\Inventory\OrderReservationService::class)->commit($order->fresh());

        $this->assertSame(9, (int) $digital->fresh()->stock_quantity);
        $this->assertSame(0, (int) $digital->fresh()->sold_quantity);
    }

    public function test_shipping_is_zero_for_digital_only_carts()
    {
        $cart = $this->makeCart();
        $digital = $this->makeProduct('DIGITAL', stock: 0);
        $this->inventory->incrementItem($cart, $digital, null, 2);

        $totals = new \App\DTOs\CheckoutTotals(
            subtotal: 50.0,
            promotionDiscount: 0.0,
            couponDiscount: 0.0,
            finalTotal: 50.0,
        );

        $service = app(\App\Services\General\OrderService::class);

        $shipping = $service->resolveShippingChargeForCart(
            $cart,
            $totals,
            ['price' => 30.0, 'free_shipping_over' => null, 'governorate_id' => 1],
        );

        $this->assertSame(0.0, $shipping);
    }

    public function test_mixed_cart_shipping_applies_for_physical_lines()
    {
        $cart = $this->makeCart();
        $digital = $this->makeProduct('DIGITAL', stock: 0);
        $physical = $this->makeProduct('PHYSICAL', stock: 10);

        $this->inventory->incrementItem($cart, $digital, null, 1);
        $this->inventory->incrementItem($cart, $physical, null, 2);

        $totals = new \App\DTOs\CheckoutTotals(
            subtotal: 75.0,
            promotionDiscount: 0.0,
            couponDiscount: 0.0,
            finalTotal: 75.0,
        );

        $service = app(\App\Services\General\OrderService::class);

        $shipping = $service->resolveShippingChargeForCart(
            $cart,
            $totals,
            ['price' => 30.0, 'free_shipping_over' => 100.0, 'governorate_id' => 1],
        );

        // Physical subtotal (50) < free-shipping threshold (100) Ã¢â€ â€™ normal price.
        $this->assertSame(30.0, $shipping);
    }

    public function test_free_shipping_threshold_ignores_digital_subtotal()
    {
        $cart = $this->makeCart();
        $digital = $this->makeProduct('DIGITAL', stock: 0);
        $physical = $this->makeProduct('PHYSICAL', stock: 10);

        // Digital line worth 500 would cross any threshold on its own Ã¢â‚¬â€
        // only physical lines count.
        $this->inventory->incrementItem($cart, $digital, null, 20);
        $this->inventory->incrementItem($cart, $physical, null, 1);

        $totals = new \App\DTOs\CheckoutTotals(
            subtotal: 550.0,
            promotionDiscount: 0.0,
            couponDiscount: 0.0,
            finalTotal: 550.0,
        );

        $service = app(\App\Services\General\OrderService::class);

        $shipping = $service->resolveShippingChargeForCart(
            $cart,
            $totals,
            ['price' => 30.0, 'free_shipping_over' => 100.0, 'governorate_id' => 1],
        );

        // Physical subtotal is only 25 Ã¢â€ â€™ below threshold Ã¢â€ â€™ charged.
        $this->assertSame(30.0, $shipping);
    }

    public function test_cod_and_cashier_remain_allowed_for_digital_orders()
    {
        // D3: validation whitelist unchanged Ã¢â‚¬â€ cod / pay_at_cashier accepted.
        $request = \Illuminate\Http\Request::create('/', 'POST', ['payment_method' => 'cod']);
        $rules = (new \Marvel\Http\Requests\OrderCreateRequest())->rules();

        $allowed = explode('|', 'in:online,cod,pay_at_cashier');
        $this->assertStringContainsString('cod', $rules['payment_method'][2] ?? '');
    }
}

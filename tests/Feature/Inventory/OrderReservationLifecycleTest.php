<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Console\Commands\CancelUnpaidOrders;
use App\DTOs\GatewayResult;
use App\Events\OrderCancelled;
use App\Listeners\RestoreProductInventory;
use App\Services\General\CartInventoryService;
use App\Services\Inventory\OrderReservationService;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * END-TO-END order-owned reservation lifecycle against the REAL database.
 * No core component is mocked ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â only the external payment-gateway HTTP layer.
 */
class OrderReservationLifecycleTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1/general';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['payment.order_timeout_hours' => 24]);

        $this->createAllTestTables();
    }

    // ------------------------------------------------------------------
    // Fixtures (real models only)
    // ------------------------------------------------------------------

    private function makeUser(string $email = null): User
    {
        return User::create([
            'name' => 'Buyer ' . Str::random(4),
            'email' => $email ?? (Str::random(8) . '@buyers.test'),
            'password' => bcrypt('secret'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function makeSimpleProduct(float $price = 100.0, int $stock = 10, string $itemType = 'PHYSICAL'): Product
    {
        return Product::create([
            'name' => 'Product ' . Str::random(6),
            'slug' => 'product-' . Str::random(10),
            'sku' => 'SKU-' . Str::upper(Str::random(6)),
            'price' => $price,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => $stock > 0,
            'stock_quantity' => $stock,
            'item_type' => $itemType,
        ]);
    }

    private function makeGovernorate(): Governorate
    {
        $country = Country::create(['name' => 'Country ' . Str::random(4), 'status' => true]);
        $governorate = Governorate::create([
            'country_id' => $country->id,
            'name' => 'Gov ' . Str::random(4),
            'status' => true,
        ]);
        ShippingPrice::create(['governorate_id' => $governorate->id, 'price' => 0, 'status' => true]);

        return $governorate;
    }

    private function addToCart(User $user, Product $product, int $qty = 1): Cart
    {
        /** @var CartInventoryService $service */
        $service = app(CartInventoryService::class);

        return DB::transaction(function () use ($service, $user, $product, $qty) {
            $cart = Cart::query()->where('user_id', $user->id)->lockForUpdate()->first()
                ?? Cart::create(['user_id' => $user->id, 'status' => 'active']);

            $service->incrementItem($cart, $product->fresh(), null, $qty);

            return $cart->fresh();
        });
    }

    private function checkout(User $user, array $overrides = [], ?int $governorateId = null)
    {
        Sanctum::actingAs($user);
        $governorate = $this->makeGovernorate();

        return $this->postJson(self::PREFIX . '/checkout', array_merge([
            'name' => 'F Test',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'address' => ['street' => '1 Main'],
            'governorate_id' => $overrides['governorate_id'] ?? ($governorateId ?? $governorate->id),
            'payment_method' => 'cod',
        ], $overrides));
    }

    /**
     * Build a realistic ACTIVE reservation directly through the service
     * (order + items + counters), bypassing HTTP.
     */
    private function makeActiveReservation(Product $product, int $qty = 2, ?User $user = null): Order
    {
        $user ??= $this->makeUser();

        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Res Test',
            'user_phone' => '01000000',
            'user_email' => $user->email,
            'address' => json_encode(['x' => 'y']),
            'shipping_method' => 'SCHEDULED',
            'payment_method' => 'online',
            'price' => $product->price * $qty,
            'shipping_price' => 0,
            'total_price' => $product->price * $qty,
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
        ]);

        $order->orderItems()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'product_quantity' => $qty,
            'product_price' => $product->price,
            'product_total_price' => $product->price * $qty,
        ]);

        app(OrderReservationService::class)->reserveForOrder($order->refresh());

        return $order->refresh();
    }

    private function payOnlineViaCallback(Order $order, float $amount): void
    {
        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah',
            'status' => 'pending',
            'amount' => $amount,
            'currency' => 'EGP',
            'invoice_id' => 'INV-' . $order->id,
            'gateway_transaction_id' => 'PAY-' . $order->id,
        ]);

        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->andReturn(new GatewayResult(
                success: true,
                gatewayTransactionId: 'PAY-' . $order->id,
                amount: $amount,
                currency: 'EGP',
                status: 'paid',
                rawResponse: ['InvoiceStatus' => 'Paid'],
            ));

        $factory = \Mockery::mock(PaymentGatewayFactory::class);
        $factory->shouldReceive('make')->andReturn($mockGateway);
        $this->app->instance(PaymentGatewayFactory::class, $factory);
    }

    private function runReaper(): void
    {
        $this->artisan('orders:cancel-unpaid')->assertExitCode(0);
    }

    // ==================================================================
    // 1. Reservation state machine (real DB arithmetic)
    // ==================================================================

    public function test_reserve_transitions_none_to_active_and_increments_reserved(): void
    {
        $product = $this->makeSimpleProduct(stock: 5);
        $order = $this->makeActiveReservation($product, qty: 2);

        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->inventory_state);
        $this->assertNotNull($order->inventory_reserved_at);
        $this->assertNotNull($order->reservation_expires_at);

        $product->refresh();
        $this->assertEquals(2, $product->reserved_quantity);
        $this->assertEquals(5, $product->stock_quantity);
        $this->assertEquals(0, $product->sold_quantity);
    }

    public function test_commit_transitions_active_to_committed_exactly_once(): void
    {
        $product = $this->makeSimpleProduct(stock: 5);
        $order = $this->makeActiveReservation($product, qty: 2);
        $service = app(OrderReservationService::class);

        $this->assertTrue($service->commit($order));
        $this->assertFalse($service->commit($order), 'Second commit must be a no-op');

        $product->refresh();
        $this->assertEquals(3, $product->stock_quantity);
        $this->assertEquals(0, $product->reserved_quantity);
        $this->assertEquals(2, $product->sold_quantity);
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->refresh()->inventory_state);
    }

    public function test_release_transitions_active_to_released_exactly_once(): void
    {
        $product = $this->makeSimpleProduct(stock: 5);
        $order = $this->makeActiveReservation($product, qty: 3);
        $service = app(OrderReservationService::class);

        $this->assertTrue($service->release($order));
        $this->assertFalse($service->release($order), 'Double release must be a no-op');

        $product->refresh();
        $this->assertEquals(0, $product->reserved_quantity);
        $this->assertEquals(5, $product->stock_quantity);
        $this->assertEquals(0, $product->sold_quantity);
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order->refresh()->inventory_state);
    }

    public function test_invalid_state_transitions_are_rejected_safely(): void
    {
        $product = $this->makeSimpleProduct(stock: 5);
        $service = app(OrderReservationService::class);

        // none -> commit / release rejected
        $bare = Order::create([
            'user_id' => $this->makeUser()->id,
            'name' => 'Bare', 'user_phone' => '1', 'user_email' => 'b@t.st',
            'address' => '{}', 'total_price' => 10, 'status' => 'pending',
        ]);
        $bare->orderItems()->create([
            'product_id' => $product->id, 'product_name' => 'P',
            'product_quantity' => 1, 'product_price' => 10, 'product_total_price' => 10,
        ]);

        $reservedBefore = $product->reserved_quantity;

        $this->assertFalse($service->commit($bare->refresh()));
        $this->assertFalse($service->release($bare->refresh()));
        $this->assertEquals($reservedBefore, $product->refresh()->reserved_quantity, 'none-state must never mutate counters');

        // committed -> release rejected ; released -> commit rejected
        $orderA = $this->makeActiveReservation($product, qty: 1);
        $service->commit($orderA);
        $snapshot = $product->refresh()->toArray();
        $this->assertFalse($service->release($orderA->refresh()), 'committed cannot be released');
        $this->assertEquals($snapshot['stock_quantity'], $product->refresh()->stock_quantity);

        $orderB = $this->makeActiveReservation($product, qty: 1);
        $service->release($orderB);
        $this->assertFalse($service->commit($orderB->refresh()), 'released cannot be committed');
        $this->assertEquals(0, $product->refresh()->sold_quantity - $snapshot['sold_quantity']);
    }

    public function test_reserve_is_idempotent_for_active_orders(): void
    {
        $product = $this->makeSimpleProduct(stock: 9);
        $order = $this->makeActiveReservation($product, qty: 2);
        $reservedOnce = $product->refresh()->reserved_quantity;

        app(OrderReservationService::class)->reserveForOrder($order->refresh());

        $this->assertSame($reservedOnce, $product->refresh()->reserved_quantity, 'Re-reserving an active order changes nothing');
    }

    public function test_multiple_lines_same_product_aggregate_before_validation(): void
    {
        // Two lines of 2 units each against stock of 3 ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ aggregate 4 > 3 ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ reject once, atomically.
        $product = $this->makeSimpleProduct(price: 50, stock: 3);
        $user = $this->makeUser();

        $cart = $this->addToCart($user, $product, 2);
        app(CartInventoryService::class)->incrementItem($cart, $product->fresh(), null, 2);
        $cart = $cart->fresh();

        $response = $this->checkout($user);
        $response->assertStatus(422); // addItemsInOrder swallowed exception ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ generic failure

        $product->refresh();
        $this->assertEquals(0, $product->reserved_quantity, 'No partial reservation may survive');
        $this->assertEquals(4, CartItem::where('cart_id', $cart->id)->sum('quantity'), 'CartItems must remain for retry');
        $this->assertDatabaseCount('orders', 0);
    }

    // ==================================================================
    // 2. REAL-DB CART TESTS ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â carts never touch inventory
    // ==================================================================

    public function test_cart_add_update_remove_clear_never_touches_inventory(): void
    {
        $product = $this->makeSimpleProduct(price: 40, stock: 7);
        $user = $this->makeUser();
        $service = app(CartInventoryService::class);

        // A) Add
        $cart = $this->addToCart($user, $product, 2);
        $product->refresh();
        $this->assertEquals(0, $product->reserved_quantity);
        $this->assertEquals(7, $product->stock_quantity);
        $this->assertEquals(0, $product->sold_quantity);
        $this->assertEquals(0, $cart->items->first()->reserved_quantity, 'cart_items.reserved_quantity stays 0');

        // B) Update quantity
        DB::transaction(fn () => $service->decrementItem($cart, $product->fresh(), null, 1));
        $product->refresh();
        $this->assertEquals(0, $product->reserved_quantity);
        $this->assertEquals(1, $cart->fresh()->items->count());

        // C) Remove item
        $item = $cart->fresh()->items->first();
        $service->releaseItem($item, true);
        $this->assertEquals(0, CartItem::where('cart_id', $cart->id)->count());
        $this->assertEquals(0, $product->refresh()->reserved_quantity);

        // D) Clear cart (re-add then clear)
        $this->addToCart($user, $product, 3);
        $service->releaseCart($cart->fresh(), true);
        $this->assertEquals(0, CartItem::where('cart_id', $cart->id)->count());
        $this->assertNotNull(Cart::find($cart->id), 'Cart row survives clear');
        $this->assertEquals(0, $product->refresh()->reserved_quantity);
    }

    public function test_cart_accepts_quantities_beyond_stock_stock_enforced_at_checkout(): void
    {
        $product = $this->makeSimpleProduct(price: 30, stock: 1);
        $user = $this->makeUser();

        // Adding 5 to a stock-of-1 product is allowed at cart levelÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¦
        $cart = $this->addToCart($user, $product, 5);
        $this->assertEquals(5, $cart->items->sum('quantity'));
        $this->assertEquals(0, $product->refresh()->reserved_quantity);

        // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¦and rejected atomically at checkout.
        $this->checkout($user)->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals(5, CartItem::where('cart_id', $cart->id)->value(DB::raw('SUM(quantity)')));
        $this->assertEquals(0, $product->refresh()->reserved_quantity);
    }

    // ==================================================================
    // 3. CHECKOUT HAPPY PATH ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â deep snapshot + metadata verification
    // ==================================================================

    public function test_checkout_happy_path_creates_order_snapshot_and_reservation(): void
    {
        $variantless = $this->makeSimpleProduct(price: 100, stock: 6);
        $user = $this->makeUser();

        $cart = $this->addToCart($user, $variantless, 2);

        $response = $this->checkout($user);
        $response->assertStatus(200);

        $order = Order::where('user_id', $user->id)->firstOrFail();
        $item = $order->orderItems()->firstOrFail();

        // Snapshot fidelity
        $this->assertSame($variantless->id, $item->product_id);
        $this->assertNull($item->product_variant_id);
        $this->assertSame($variantless->name, $item->product_name);
        $this->assertSame($variantless->sku, $item->product_sku);
        $this->assertEquals(2, $item->product_quantity);
        $this->assertEquals(100.0, (float) $item->product_price);
        $this->assertEquals(200.0, (float) $item->product_total_price);

        // Reservation ownership + metadata (allow 1s drift due to now() vs created_at microsecond)
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->inventory_state);
        $this->assertNotNull($order->inventory_reserved_at);
        $expectedHours = \App\Services\Inventory\OrderReservationService::timeoutHoursFor($order);
        $expectedExpiry = $order->created_at->copy()->addHours($expectedHours);
        $this->assertTrue(
            $order->reservation_expires_at->diffInSeconds($expectedExpiry) <= 1,
            'reservation_expires_at should be created_at +'.$expectedHours.'h within 1s tolerance, got '.$order->reservation_expires_at.' expected '.$expectedExpiry
        );

        // Inventory arithmetic
        $variantless->refresh();
        $this->assertEquals(2, $variantless->reserved_quantity);
        $this->assertEquals(6, $variantless->stock_quantity);
        $this->assertEquals(0, $variantless->sold_quantity);

        // Cart emptied, row kept
        $this->assertSame($cart->id, Cart::where('user_id', $user->id)->value('id'), 'Same cart row survives');
        $this->assertEquals(0, CartItem::where('cart_id', $cart->id)->count());
    }

    // ==================================================================
    // 4. CHECKOUT ROLLBACK TRIO
    // ==================================================================

    public function test_rollback_when_product_stock_insufficient(): void
    {
        $product = $this->makeSimpleProduct(price: 20, stock: 1);
        $user = $this->makeUser();

        $this->addToCart($user, $product, 2);
        $this->checkout($user)->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_products', 0);
        $product->refresh();
        $this->assertEquals(0, $product->reserved_quantity);
        $this->assertEquals(1, $product->stock_quantity);
        $this->assertEquals(1, CartItem::count(), 'CartItems survive failed checkout');
    }

    public function test_rollback_when_variant_stock_insufficient(): void
    {
        $product = Product::create([
            'name' => 'Variable P', 'slug' => 'var-' . Str::random(8),
            'price' => 80, 'product_type' => ProductType::VARIABLE,
            'status' => true, 'in_stock' => true, 'stock_quantity' => 9,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'V-' . Str::random(6),
            'price' => 80, 'stock_quantity' => 1,
        ]);

        $user = $this->makeUser();
        $cart = Cart::create(['user_id' => $user->id, 'status' => 'active']);
        CartItem::create([
            'cart_id' => $cart->id, 'product_id' => $product->id,
            'product_variant_id' => $variant->id, 'quantity' => 2,
            'price' => 80, 'total_price' => 160, 'shipping_method' => 'SCHEDULED',
        ]);

        $this->checkout($user)->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
        $variant->refresh();
        $this->assertEquals(0, $variant->reserved_quantity);
        $this->assertEquals(1, CartItem::where('cart_id', $cart->id)->count());
    }

    // ==================================================================
    // 5/6. PAYMENT SUCCESS ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â F1 regression: cart is irrelevant
    // ==================================================================

    public function test_payment_success_commits_exact_order_lines_only_f1_regression(): void
    {
        $productA = $this->makeSimpleProduct(price: 100, stock: 10);
        $productB = $this->makeSimpleProduct(price: 60, stock: 8);
        $user = $this->makeUser();

        $order = $this->makeActiveReservation($productA, qty: 2);
        $order->update(['payment_status' => Order::PAYMENT_STATUS_PENDING]);

        // Simulate the user refilling their cart AFTER checkout (Product B).
        $refillCart = $this->addToCart($user, $productB, 3);

        $this->payOnlineViaCallback($order, (float) $order->total_price);
        $this->get(self::PREFIX . '/checkout/callback?paymentId=PAY-' . $order->id . '&type=mobile');

        $order->refresh();
        $this->assertEquals('completed', $order->status);
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->inventory_state);

        $productA->refresh();
        $this->assertEquals(8, $productA->stock_quantity, 'Only ORDER line A deducted (10-2)');
        $this->assertEquals(0, $productA->reserved_quantity);
        $this->assertEquals(2, $productA->sold_quantity);

        $productB->refresh();
        $this->assertEquals(8, $productB->stock_quantity, 'Refilled cart product B untouched by payment');
        $this->assertEquals(0, $productB->reserved_quantity);
        $this->assertEquals(0, $productB->sold_quantity);
        $this->assertEquals(3, CartItem::where('cart_id', $refillCart->id)->sum('quantity'), 'Product B still in cart');
    }

    public function test_payment_failure_keeps_reservation_active_for_retry(): void
    {
        $product = $this->makeSimpleProduct(stock: 4);
        $user = $this->makeUser();
        $order = $this->makeActiveReservation($product, qty: 2, user: $user);

        // ONE gateway binding, sequenced like the real world:
        // attempt #1 declines, attempt #2 (retry of the SAME order) succeeds.
        Transaction::create([
            'order_id' => $order->id, 'user_id' => $user->id,
            'payment_method' => 'myfatoorah', 'status' => 'pending',
            'amount' => (float) $order->total_price, 'currency' => 'EGP',
            'invoice_id' => 'INV-' . $order->id,
            'gateway_transaction_id' => 'GW-' . $order->id,
        ]);

        $declined = new GatewayResult(success: false, errorMessage: 'declined', status: 'failed');
        $paid = new GatewayResult(
            success: true,
            gatewayTransactionId: 'GW-' . $order->id,
            amount: (float) $order->total_price,
            currency: 'EGP',
            status: 'paid',
            rawResponse: ['InvoiceStatus' => 'Paid'],
        );

        $gateway = \Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('verifyPayment')->twice()->andReturn($declined, $paid);
        $factory = \Mockery::mock(PaymentGatewayFactory::class);
        $factory->shouldReceive('make')->twice()->andReturn($gateway);
        $this->app->instance(PaymentGatewayFactory::class, $factory);

        // Attempt #1 - declined (web flow redirects; DB state is the contract).
        $this->get(self::PREFIX . '/checkout/callback?paymentId=GW-' . $order->id);

        $order->refresh();
        $this->assertEquals('pending', $order->status);
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->inventory_state);
        $this->assertEquals('failed', $order->transactions()->latest('id')->value('status'));
        $this->assertEquals(2, $product->refresh()->reserved_quantity, 'Failure does NOT release reservation');

        // Attempt #2 - same pending transaction retried at the gateway, now paid.
        Transaction::where('order_id', $order->id)->update(['status' => 'pending']);
        $resp2 = $this->get(self::PREFIX . '/checkout/callback?paymentId=GW-' . $order->id . '&type=mobile');

        if ($resp2->status() === 200) {
            $order->refresh();
            $this->assertEquals('completed', $order->status);
            $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->inventory_state);
            $product->refresh();
            $this->assertEquals(2, $product->sold_quantity, 'Committed exactly once across retry');
            $this->assertEquals(0, $product->reserved_quantity);
            $this->assertEquals(2, $product->stock_quantity);
        } else {
            // Web redirect path still must have processed the success.
            $this->assertEquals(302, $resp2->status());
            $order->refresh();
            $this->assertEquals('completed', $order->status);
            $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->inventory_state);
        }
    }

    // ==================================================================
    // 10. CONCURRENCY ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â serialized-real-path equivalents
    // ==================================================================

    public function test_same_cart_second_checkout_after_commit_fails_cleanly(): void
    {
        $product = $this->makeSimpleProduct(stock: 5);
        $user = $this->makeUser();

        $this->addToCart($user, $product, 1);

        // Request A commits fully.
        $this->checkout($user)->assertStatus(200);
        $this->assertEquals(1, Order::where('user_id', $user->id)->count());

        // Request B arrives after A committed: cart empty ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ clean rejection.
        $this->checkout($user)->assertStatus(400);
        $this->assertEquals(1, Order::where('user_id', $user->id)->count(), 'No duplicate order');
        $this->assertEquals(1, $product->refresh()->reserved_quantity, 'Single reservation');
    }

    public function test_last_unit_two_users_exactly_one_wins(): void
    {
        $scarce = $this->makeSimpleProduct(price: 70, stock: 1);
        $alice = $this->makeUser();
        $bob = $this->makeUser();

        $this->addToCart($alice, $scarce, 1);
        $this->addToCart($bob, $scarce, 1);

        $this->checkout($alice)->assertStatus(200);
        $this->checkout($bob)->assertStatus(422);

        $this->assertEquals(1, Order::count(), 'Exactly one order');
        $scarce->refresh();
        $this->assertEquals(1, $scarce->reserved_quantity);
        $this->assertEquals(1, $scarce->stock_quantity);
        $this->assertTrue($scarce->reserved_quantity <= $scarce->stock_quantity, 'Never over capacity');
    }

    public function test_payment_vs_reaper_payment_wins_serialization(): void
    {
        $product = $this->makeSimpleProduct(stock: 3);
        $user = $this->makeUser();
        $order = $this->makeActiveReservation($product, qty: 1, user: $user);

        // Force expiry so the reaper is ELIGIBLE, then let payment win the race.
        $order->update(['reservation_expires_at' => now()->subSecond()]);

        $this->payOnlineViaCallback($order, (float) $order->total_price);
        $this->get(self::PREFIX . '/checkout/callback?paymentId=PAY-' . $order->id . '&type=mobile');
        $this->runReaper();

        $order->refresh();
        $this->assertEquals('completed', $order->status, 'Payment committed first; reaper no-op');
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->inventory_state);
        $product->refresh();
        $this->assertEquals(2, $product->stock_quantity, 'Deducted exactly once');
        $this->assertEquals(1, $product->sold_quantity);
        $this->assertEquals(0, $product->reserved_quantity);
    }

    public function test_payment_vs_reaper_reaper_wins_serialization(): void
    {
        $product = $this->makeSimpleProduct(stock: 3);
        $user = $this->makeUser();
        $order = $this->makeActiveReservation($product, qty: 1, user: $user);
        $order->update(['reservation_expires_at' => now()->subSecond()]);

        // Reaper wins the race: cancels and releases BEFORE payment lands.
        $this->runReaper();

        $order->refresh();
        $this->assertEquals('cancelled', $order->status);
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order->inventory_state);
        $this->assertEquals(0, $product->refresh()->reserved_quantity);

        // Late gateway success must NOT resurrect or deduct.
        $this->payOnlineViaCallback($order, (float) $order->total_price);
        $this->get(self::PREFIX . '/checkout/callback?paymentId=PAY-' . $order->id . '&type=mobile');

        $order->refresh();
        $this->assertEquals('cancelled', $order->status, 'Cancelled order cannot be resurrected');
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order->inventory_state);
        $product->refresh();
        $this->assertEquals(3, $product->stock_quantity, 'No deduction for cancelled order');
        $this->assertEquals(0, $product->sold_quantity);
    }

    public function test_reaper_is_idempotent_on_repeat_runs(): void
    {
        $product = $this->makeSimpleProduct(stock: 4);
        $order = $this->makeActiveReservation($product, qty: 2);
        $order->update(['reservation_expires_at' => now()->subMinute()]);

        $this->runReaper();
        $afterFirst = $product->refresh()->toArray();

        $this->runReaper();
        $afterSecond = $product->refresh()->toArray();

        $this->assertSame($afterFirst['reserved_quantity'], $afterSecond['reserved_quantity']);
        $this->assertSame($afterFirst['sold_quantity'], $afterSecond['sold_quantity']);
        $this->assertEquals(0, $afterFirst['reserved_quantity'], 'First run released the exact reservation');
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order->refresh()->inventory_state);
        $this->assertEquals(0, (int) DB::table('transactions')->where('order_id', $order->id)->where('status', 'pending')->count());
    }

    public function test_reaper_boundary_seconds(): void
    {
        $expired = $this->makeActiveReservation($this->makeSimpleProduct(stock: 2), qty: 1);
        $expired->update(['reservation_expires_at' => now()->subSecond()]);

        $edgeNow = $this->makeActiveReservation($this->makeSimpleProduct(stock: 2), qty: 1);
        $edgeNow->update(['reservation_expires_at' => now()]);

        $future = $this->makeActiveReservation($this->makeSimpleProduct(stock: 2), qty: 1);
        $future->update(['reservation_expires_at' => now()->addSecond()]);

        $almostDay = $this->makeActiveReservation($this->makeSimpleProduct(stock: 2), qty: 1);
        $almostDay->update(['reservation_expires_at' => now()->addHours(24)->subMinutes(1)]);

        $pastDay = $this->makeActiveReservation($this->makeSimpleProduct(stock: 2), qty: 1);
        $pastDay->update(['reservation_expires_at' => now()->addHours(24)->addMinutes(1)->setHour(0)->setMinute(0)]);

        $this->runReaper();

        $this->assertEquals('cancelled', $expired->refresh()->status, '-1s ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¡ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ cancelled');
        $this->assertEquals('cancelled', $edgeNow->refresh()->status, '=now ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¡ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ cancelled (<=)');
        $this->assertEquals('pending', $future->refresh()->status, '+1s ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¡ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ active');
        $this->assertEquals('pending', $almostDay->refresh()->status, '23h59m ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¡ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ active');
    }

    public function test_cod_order_expires_and_releases_without_gateway_check(): void
    {
        $product = $this->makeSimpleProduct(stock: 5);
        $order = $this->makeActiveReservation($product, qty: 1);
        $order->update([
            'payment_method' => 'cod',
            'reservation_expires_at' => now()->subMinute(),
        ]);
        Transaction::create([
            'order_id' => $order->id, 'user_id' => $order->user_id,
            'payment_method' => 'cod', 'status' => 'pending', 'amount' => 1,
        ]);

        $this->runReaper();

        $order->refresh();
        $this->assertEquals('cancelled', $order->status);
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order->inventory_state);
        $this->assertEquals(0, $product->refresh()->reserved_quantity);
        $this->assertEquals(0, Transaction::where('order_id', $order->id)->where('status', 'pending')->count());
    }

    public function test_reaper_skips_cancellation_when_gateway_already_paid(): void
    {
        $product = $this->makeSimpleProduct(stock: 5);
        $order = $this->makeActiveReservation($product, qty: 1);
        $order->update([
            'payment_method' => 'online',
            'reservation_expires_at' => now()->subMinute(),
        ]);
        Transaction::create([
            'order_id' => $order->id, 'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah', 'status' => 'pending',
            'amount' => 1, 'currency' => 'EGP',
            'gateway_transaction_id' => 'GW-LATE-' . $order->id,
        ]);

        // External gateway reports PAID (external-system mock).
        $gateway = \Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('verifyPayment')->with('GW-LATE-' . $order->id)
            ->andReturn(new GatewayResult(success: true, gatewayTransactionId: 'GW-LATE-' . $order->id, amount: 1, currency: 'EGP', status: 'paid'));
        $factory = \Mockery::mock(PaymentGatewayFactory::class);
        $factory->shouldReceive('make')->with('myfatoorah')->andReturn($gateway);
        $this->app->instance(PaymentGatewayFactory::class, $factory);

        $this->runReaper();

        $order->refresh();
        $this->assertEquals('pending', $order->status, 'Paid-at-gateway orders are left for the normal flow');
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->inventory_state);
        $this->assertEquals(1, $product->refresh()->reserved_quantity);
    }

    // ==================================================================
    // 11. ADMIN CANCELLATION
    // ==================================================================

    public function test_admin_cancel_unpaid_releases_reservation(): void
    {
        $product = $this->makeSimpleProduct(stock: 5);
        $order = $this->makeActiveReservation($product, qty: 2);

        app(\App\Services\General\OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id);

        $order->refresh();
        $this->assertEquals('cancelled', $order->status);
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order->inventory_state);
        $this->assertEquals(0, $product->refresh()->reserved_quantity);
        $this->assertNotNull($order->cancelled_at);
    }

    public function test_admin_cancel_paid_restores_inventory_once_via_listener(): void
    {
        $product = $this->makeSimpleProduct(stock: 5);
        $order = $this->makeActiveReservation($product, qty: 2);

        // Pay first (committed), order enters processing, then admin-cancels.
        app(OrderReservationService::class)->commit($order->refresh());
        $order->forceFill(['paid_at' => now(), 'status' => 'processing'])->save();
        $stockAfterSale = $product->refresh()->stock_quantity; // 3

        Event::fake([OrderCancelled::class]);
        app(\App\Services\General\OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id);

        $order->refresh();
        $this->assertEquals('cancelled', $order->status);
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->inventory_state, 'Release is a no-op for committed orders');

        // Emulate the async queue worker running the real listener exactly once.
        (new RestoreProductInventory())->handle(new OrderCancelled($order));

        $product->refresh();
        $this->assertEquals($stockAfterSale + 2, $product->stock_quantity, 'Paid-order restoration ran once');
        $this->assertEquals(0, $product->sold_quantity);
        $this->assertNotNull($order->refresh()->inventory_restored_at);

        // Idempotency: second worker run restores nothing more.
        (new RestoreProductInventory())->handle(new OrderCancelled($order));
        $this->assertEquals($stockAfterSale + 2, $product->refresh()->stock_quantity);
        $this->assertNotNull($order->refresh()->inventory_restored_at);
    }

    // ==================================================================
    // 16. DIGITAL PRODUCTS
    // ==================================================================

    public function test_digital_checkout_reserves_nothing_physical(): void
    {
        $digital = $this->makeSimpleProduct(price: 25, stock: 5, itemType: 'DIGITAL');
        $physical = $this->makeSimpleProduct(price: 40, stock: 5);
        $user = $this->makeUser();

        $this->addToCart($user, $digital, 3);
        $this->checkout($user)->assertStatus(200);

        $digital->refresh();
        $this->assertEquals(0, $digital->reserved_quantity, 'Digital reserves nothing');
        $this->assertEquals(5, $digital->stock_quantity);
        $this->assertEquals(0, $digital->sold_quantity);
        $this->assertEquals(0, $physical->refresh()->reserved_quantity, 'Other physical stock untouched');

        $order = Order::where('user_id', $user->id)->first();
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->inventory_state);

        // Payment success alters NO physical counters.
        $order->forceFill(['paid_at' => null])->save();
        Transaction::create([
            'order_id' => $order->id, 'user_id' => $user->id,
            'payment_method' => 'myfatoorah', 'status' => 'pending',
            'amount' => (float) $order->total_price, 'currency' => 'EGP',
            'invoice_id' => 'D-' . $order->id, 'gateway_transaction_id' => 'DP-' . $order->id,
        ]);
        $gateway = \Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('verifyPayment')->andReturn(new GatewayResult(success: true, gatewayTransactionId: 'DP-' . $order->id, amount: (float) $order->total_price, currency: 'EGP', status: 'paid'));
        $factory = \Mockery::mock(PaymentGatewayFactory::class);
        $factory->shouldReceive('make')->andReturn($gateway);
        $this->app->instance(PaymentGatewayFactory::class, $factory);

        $this->get(self::PREFIX . '/checkout/callback?paymentId=DP-' . $order->id . '&type=mobile');

        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->refresh()->inventory_state);
        $digital->refresh();
        $this->assertEquals(5, $digital->stock_quantity);
        $this->assertEquals(0, $digital->sold_quantity, 'Payment success does not alter digital/physical counters');
    }
}

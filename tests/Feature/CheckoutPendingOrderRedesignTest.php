<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Events\OrderCancelled;
use App\Events\PaymentFailed;
use App\Services\Checkout\OrderCreationService;
use App\Services\General\CartInventoryService;
use App\Services\General\OrderService;
use App\Services\General\PromotionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Marvel\Enums\PromotionType;
use Tests\Concerns\CreatesTestTables;
use Tests\Concerns\WithInvoiceTables;
use Tests\TestCase;

/**
 * ORDER-OWNED INVENTORY RESERVATION CONTRACT.
 *
 * Checkout atomically: creates the pending Order, snapshots its items,
 * reserves inventory against the ORDER, and empties the CartItems while the
 * Cart row survives as a reusable container. Payment commits the exact
 * reservation; 24-hour expiry releases it. The current Cart is never consulted.
 */
class CheckoutPendingOrderRedesignTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables, WithInvoiceTables;

    private const PREFIX = '/api/v1/general';

    private User $user;
    private Product $product;
    private Governorate $governorate;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();
        $this->createInvoiceTables();

        $this->user = User::create([
            'name' => 'Pending Order User',
            'email' => 'pending@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->product = Product::create([
            'name' => 'Pending Order Product',
            'slug' => 'pending-product-' . Str::random(8),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 50,
        ]);

        $country = Country::create(['name' => 'Test Country', 'status' => true]);
        $this->governorate = Governorate::create([
            'country_id' => $country->id,
            'name' => 'Test Governorate',
            'status' => true,
        ]);
        ShippingPrice::create([
            'governorate_id' => $this->governorate->id,
            'price' => 20,
            'status' => true,
        ]);
    }

    private function auth(): void
    {
        Sanctum::actingAs($this->user);
    }

    /**
     * Build cart state through the REAL application service so fixtures obey
     * the production invariants (no reservation, real pricing, real merging).
     */
    private function addItemToCart(int $quantity = 1, float $price = 100.00, ?User $user = null): Cart
    {
        $target = $user ?? $this->user;

        if ($price !== (float) $this->product->price) {
            $this->product->update(['price' => $price]);
        }

        /** @var CartInventoryService $inventory */
        $inventory = app(CartInventoryService::class);

        $cart = DB::transaction(function () use ($inventory, $quantity) {
            $cart = Cart::query()->where('user_id', $this->user->id)->lockForUpdate()->first()
                ?? Cart::create(['user_id' => $this->user->id, 'status' => 'active']);

            return $inventory->incrementItem($cart, $this->product->fresh(), null, $quantity);
        });

        return $cart->cart()->firstOrFail();
    }

    private function grantPermission(): void
    {
        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'update-order-status', 'guard_name' => 'api']);
        $this->user->givePermissionTo($permission);
    }

    private function createPromotion(): Promotion
    {
        return Promotion::create([
            'name' => 'Test Promotion',
            'slug' => 'test-promotion-' . Str::random(6),
            'code' => 'PROMO-' . Str::random(6),
            'type' => PromotionType::PRICE,
            'type_amount' => 'percentage',
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
            'start_at' => Carbon::yesterday()->format('Y-m-d'),
            'end_at' => Carbon::tomorrow()->format('Y-m-d'),
        ]);
    }

    private function createCoupon(string $code = 'PENDING10'): Coupon
    {
        return Coupon::create([
            'code' => $code,
            'name' => 'Pending Test Coupon',
            'slug' => 'coupon-' . Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
    }

    private function checkout(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::PREFIX . '/checkout', array_merge([
            'name' => 'John Doe',
            'user_phone' => '01000000001',
            'user_email' => 'john@example.com',
            'address' => ['street' => '123 Main St', 'city' => 'Cairo'],
            'governorate_id' => $this->governorate->id,
            'payment_method' => 'cod',
        ], $overrides));
    }

    private function markOrderAsPaid(Order $order): \Illuminate\Testing\TestResponse
    {
        $this->grantPermission();

        return $this->postJson(self::PREFIX . '/checkout/cod/' . $order->id . '/mark-paid');
    }

    // =========================================================================
    // Core: Order creation owns the reservation; cart is emptied, not destroyed
    // =========================================================================

    public function test_checkout_creates_pending_order_and_empties_cart_items(): void
    {
        $this->auth();
        $cart = $this->addItemToCart();

        $response = $this->checkout();
        $response->assertStatus(200);

        $order = Order::where('user_id', $this->user->id)->first();
        $this->assertNotNull($order);
        $this->assertEquals('pending', $order->status);

        // Same cart row survives…
        $cart = $cart->refresh() ?? Cart::where('user_id', $this->user->id)->first();
        $this->assertNotNull(Cart::find($cart->id), 'The Cart record itself must NOT be deleted');
        $this->assertEquals('active', $cart->status);

        // …but it holds zero items.
        $this->assertEquals(0, \Marvel\Database\Models\CartItem::where('cart_id', $cart->id)->count());
    }

    public function test_checkout_reserves_inventory_without_deducting_stock(): void
    {
        $this->auth();
        $this->addItemToCart(2);

        $stockBefore = $this->product->refresh()->stock_quantity;
        $soldBefore = $this->product->sold_quantity;

        $this->checkout();

        $product = $this->product->refresh();
        $this->assertEquals($stockBefore, $product->stock_quantity, 'Stock must NOT be deducted at checkout');
        $this->assertEquals($soldBefore, $product->sold_quantity, 'Sold quantity must NOT change at checkout');
        $this->assertEquals(2, $product->reserved_quantity, 'Checkout must reserve inventory for the ORDER');

        $order = Order::where('user_id', $this->user->id)->first();
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->inventory_state);
        $this->assertNotNull($order->inventory_reserved_at);
        $this->assertNotNull($order->reservation_expires_at);
    }

    public function test_checkout_stores_explicit_24h_reservation_expiry(): void
    {
        // Business rule: non-COD (online/cashier) = 24h, COD = 168h (7 days)
        // This test verifies non-COD 24h expiry, so use payment_method online
        config(['payment.order_timeout_hours' => 24]);
        config(['payment.cod_order_timeout_hours' => 168]);

        $this->auth();
        $this->addItemToCart();

        Carbon::setTestNow(now());
        $this->checkout(['payment_method' => 'online']);

        $order = Order::where('user_id', $this->user->id)->first();

        $expectedExpiry = $order->created_at->copy()->addHours(24);
        $this->assertTrue(
            $order->reservation_expires_at->equalTo($expectedExpiry),
            "reservation_expires_at must equal created_at + 24h for non-COD (got {$order->reservation_expires_at} vs {$expectedExpiry})"
        );
    }

    public function test_cod_reservation_expiry_is_168h(): void
    {
        config(['payment.cod_order_timeout_hours' => 168]);
        config(['payment.order_timeout_hours' => 24]);

        $this->auth();
        $this->addItemToCart();

        Carbon::setTestNow(now());
        $this->checkout(['payment_method' => 'cod']);

        $order = Order::where('user_id', $this->user->id)->first();

        $expectedExpiry = $order->created_at->copy()->addHours(168);
        $this->assertTrue(
            $order->reservation_expires_at->equalTo($expectedExpiry),
            "reservation_expires_at must equal created_at + 168h for COD (got {$order->reservation_expires_at} vs {$expectedExpiry})"
        );
    }

    // =========================================================================
    // Cart independence after checkout
    // =========================================================================

    public function test_second_checkout_after_refill_reuses_pending_order(): void
    {
        $this->auth();
        $this->addItemToCart(1, 100.00);

        $this->checkout();
        $firstOrderId = Order::where('user_id', $this->user->id)->first()->id;

        // Refill the SAME surviving cart row with different content.
        $cart = Cart::where('user_id', $this->user->id)->firstOrFail();
        app(CartInventoryService::class)->incrementItem($cart, $this->product->fresh(), null, 3);

        $this->checkout();

        // RULE 4-5: Should reuse the pending order, NOT create a duplicate
        $orders = Order::where('user_id', $this->user->id)->where('status', 'pending')->get();
        $this->assertCount(1, $orders, 'Refilled cart must REUSE the pending order (Rule 4-5)');

        // The reused order should be updated with new quantity
        $reusedOrder = $orders->first();
        $this->assertEquals($firstOrderId, $reusedOrder->id, 'Must reuse the same order ID');
        $this->assertEquals(3, $reusedOrder->orderItems()->sum('product_quantity'), 'Order items synced with new cart');
    }

    public function test_cart_is_reusable_with_same_row_after_checkout(): void
    {
        $this->auth();
        $cartBefore = $this->addItemToCart();
        $cartId = $cartBefore->id;

        $this->checkout();

        // Add again → SAME cart id, brand-new item row.
        $reservedFromPendingOrder = $this->product->refresh()->reserved_quantity;
        $this->assertEquals(1, $reservedFromPendingOrder, 'Pending order holds its own reservation');

        $cartAfter = app(CartInventoryService::class)->incrementItem(
            Cart::findOrFail($cartId), $this->product->fresh(), null, 1
        );

        $this->assertSame($cartId, $cartAfter->cart_id, 'Re-add must reuse the identical cart row');
        $this->assertEquals(1, \Marvel\Database\Models\CartItem::where('cart_id', $cartId)->count());
        $this->assertEquals(
            $reservedFromPendingOrder,
            $this->product->refresh()->reserved_quantity,
            'Cart operations never add reservations'
        );
    }

    // =========================================================================
    // Inventory finalization (COD)
    // =========================================================================

    public function test_mark_cod_as_paid_commits_reservation(): void
    {
        $this->auth();
        $this->addItemToCart();

        $this->checkout();
        $order = Order::where('user_id', $this->user->id)->first();

        $stockBefore = $this->product->refresh()->stock_quantity;

        $this->markOrderAsPaid($order)->assertStatus(200);

        $product = $this->product->refresh();
        $this->assertEquals($stockBefore - 1, $product->stock_quantity, 'Payment commits: stock deducted once');
        $this->assertEquals(1, $product->sold_quantity);
        $this->assertEquals(0, $product->reserved_quantity);

        $order->refresh();
        $this->assertEquals('completed', $order->status);
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->inventory_state);
    }

    public function test_cod_marked_paid_twice_is_idempotent(): void
    {
        $this->auth();
        $this->addItemToCart();

        $this->checkout();
        $order = Order::where('user_id', $this->user->id)->first();

        $this->grantPermission();
        $this->postJson(self::PREFIX . '/checkout/cod/' . $order->id . '/mark-paid')->assertStatus(200);
        $this->postJson(self::PREFIX . '/checkout/cod/' . $order->id . '/mark-paid')->assertStatus(422);

        $product = $this->product->refresh();
        $this->assertEquals($this->product->stock_quantity, $product->stock_quantity);
        $this->assertEquals(1, $product->sold_quantity, 'Sold incremented exactly once');
    }

    // =========================================================================
    // Promotion / coupon lifecycle timing (unchanged policy, new plumbing)
    // =========================================================================

    public function test_promotion_usage_not_incremented_at_checkout(): void
    {
        $this->auth();
        $this->addItemToCart();

        $promotion = $this->createPromotion();

        $initialUsage = $promotion->usage;

        $this->checkout(['selected_promotion_id' => $promotion->id]);

        $this->assertEquals($initialUsage, $promotion->refresh()->usage, 'Promotion usage consumed only at payment');
    }

    public function test_promotion_usage_incremented_after_cod_paid(): void
    {
        $this->auth();
        $this->addItemToCart();

        $promotion = $this->createPromotion();

        $this->checkout(['selected_promotion_id' => $promotion->id]);

        $order = Order::where('user_id', $this->user->id)->first();
        $order->update(['promotion_id' => $promotion->id]);

        $this->markOrderAsPaid($order);

        $this->assertEquals(1, $promotion->refresh()->usage, 'Promotion usage incremented exactly once after payment');
    }

    public function test_coupon_usage_not_recorded_at_checkout(): void
    {
        $this->auth();
        $this->addItemToCart();

        $coupon = $this->createCoupon();

        Cart::where('user_id', $this->user->id)->update(['coupon' => $coupon->code]);

        $this->checkout(['selected_gift_product_id' => null]);

        $this->assertDatabaseMissing('coupon_usages', [
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_coupon_usage_recorded_after_cod_paid(): void
    {
        $this->auth();
        $this->addItemToCart();

        $coupon = $this->createCoupon();

        Cart::where('user_id', $this->user->id)->update(['coupon' => $coupon->code]);

        $this->checkout();

        $order = Order::where('user_id', $this->user->id)->first();

        $this->markOrderAsPaid($order);

        $this->assertDatabaseHas('coupon_usages', [
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
        ]);
    }

    // =========================================================================
    // Failure behavior
    // =========================================================================

    public function test_payment_failure_does_not_cancel_order_or_release_reservation(): void
    {
        $this->auth();
        $this->addItemToCart();

        $this->checkout(['payment_method' => 'online']);

        $order = Order::where('user_id', $this->user->id)->first();
        $order->update(['payment_method' => 'online']);

        $this->getJson(self::PREFIX . '/checkout/error-callback?paymentId=invalid_123');

        $order->refresh();
        $this->assertEquals('pending', $order->status, 'Order remains payable after failure');
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->inventory_state, 'Reservation survives failure for retry');
        $this->assertEquals(1, $this->product->refresh()->reserved_quantity);
    }

    // =========================================================================
    // 24-HOUR REAPER
    // =========================================================================

    public function test_reaper_cancels_expired_reservation_and_releases_stock(): void
    {
        $this->auth();
        $this->addItemToCart();

        $this->checkout();

        $order = Order::where('user_id', $this->user->id)->first();
        $order->update(['reservation_expires_at' => now()->subMinute()]);
        $cartId = Cart::where('user_id', $this->user->id)->value('id');

        config(['payment.order_timeout_hours' => 24]);

        $this->artisan('orders:cancel-unpaid')
            ->expectsOutputToContain('Cancelled 1 unpaid order(s).')
            ->assertExitCode(0);

        $order->refresh();
        $this->assertEquals('cancelled', $order->status);
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order->inventory_state);
        $this->assertEquals(0, $this->product->refresh()->reserved_quantity, 'Exact reservation released');

        // Cart is NEVER touched by the reaper (already empty from checkout).
        $this->assertEquals(0, \Marvel\Database\Models\CartItem::where('cart_id', $cartId)->count());
        $this->assertDatabaseHas('carts', ['id' => $cartId]);
    }

    public function test_reaper_skips_unexpired_reservations(): void
    {
        $this->auth();
        $this->addItemToCart();
        $this->checkout();

        $this->artisan('orders:cancel-unpaid')->assertExitCode(0);

        $order = Order::where('user_id', $this->user->id)->first();
        $this->assertEquals('pending', $order->status);
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->refresh()->inventory_state);
    }

    // =========================================================================
    // Events
    // =========================================================================

    public function test_each_checkout_fires_order_created_event_once(): void
    {
        Event::fake([OrderCreated::class]);

        $this->auth();
        $this->addItemToCart();
        $this->checkout();

        Event::assertDispatched(OrderCreated::class, 1);

        $this->addItemToCart(2, 200.00);
        $this->checkout();

        Event::assertDispatched(OrderCreated::class, 2);
    }
}

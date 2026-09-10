<?php

namespace Tests\Feature;

use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Tests\TestCase;

class CouponSystemTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';

    private User $user;
    private Product $product;
    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['type' => 'user']);
        $this->product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product-' . Str::random(8),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'quantity' => 50,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCoupon(string $code, array $overrides = []): Coupon
    {
        $coupon = Coupon::create(array_merge([
            'name' => 'Test Coupon',
            'slug' => 'coupon-' . Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ], $overrides));

        $coupon->update(['code' => $code]);

        return $coupon->fresh();
    }

    private function createCartWithItem(): Cart
    {
        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => 100.00,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 100.00,
            'total_price' => 100.00,
            'shipping_method' => 'SCHEDULED',
        ]);

        return $cart->fresh();
    }

    private function authUser(): void
    {
        Sanctum::actingAs($this->user);
    }

    private function createCountryAndGovernorate(): Governorate
    {
        $country = Country::create([
            'name' => 'Test Country',
            'status' => true,
        ]);

        $governorate = Governorate::create([
            'country_id' => $country->id,
            'name' => 'Test Gov',
            'status' => true,
            'is_fast_shipping_enabled' => false,
        ]);

        ShippingPrice::create([
            'governorate_id' => $governorate->id,
            'price' => 20,
            'status' => true,
        ]);

        return $governorate;
    }

    // =========================================================================
    // Apply Coupon - Success
    // =========================================================================

    /** @test */
    public function apply_valid_coupon_success(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $this->createCoupon('TEST10');

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'TEST10',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'coupon' => 'TEST10',
        ]);
    }

    /** @test */
    public function apply_same_coupon_returns_already_applied(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $this->createCoupon('TEST10');

        $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'TEST10']);
        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'TEST10']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.already_applied', true);
    }

    /** @test */
    public function apply_different_coupon_replaces_existing(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $this->createCoupon('FIRST10');
        $this->createCoupon('SECOND20', ['discount' => 20]);

        $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'FIRST10']);
        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'SECOND20']);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'coupon' => 'SECOND20',
        ]);

        $this->assertDatabaseMissing('carts', [
            'user_id' => $this->user->id,
            'coupon' => 'FIRST10',
        ]);
    }

    // =========================================================================
    // Apply Coupon - Failure
    // =========================================================================

    /** @test */
    public function apply_expired_coupon_returns_error(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $this->createCoupon('EXPIRED', [
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonth(),
        ]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'EXPIRED']);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function apply_disabled_coupon_returns_error(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $this->createCoupon('DISABLED', ['status' => false]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'DISABLED']);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function apply_usage_limit_reached_returns_error(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $this->createCoupon('LIMITED', [
            'limiter' => 5,
            'used' => 5,
        ]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'LIMITED']);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function apply_nonexistent_coupon_returns_error(): void
    {
        $this->authUser();
        $this->createCartWithItem();

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'NONEXISTENT']);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function apply_coupon_without_auth_returns_401(): void
    {
        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'ANY']);

        $response->assertUnauthorized();
    }

    /** @test */
    public function apply_coupon_without_cart_returns_error(): void
    {
        $this->authUser();
        $this->createCoupon('NOCART');

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'NOCART']);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function apply_already_used_coupon_returns_error(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $coupon = $this->createCoupon('USEDONCE');

        CouponUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
            'used_at' => now(),
        ]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'USEDONCE']);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function apply_product_restricted_coupon_returns_error(): void
    {
        $this->authUser();
        $this->createCartWithItem();

        $restrictedProduct = Product::create([
            'name' => 'Restricted Product',
            'slug' => 'restricted-' . Str::random(8),
            'price' => 200,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
        ]);

        $coupon = $this->createCoupon('RESTRICT');
        $coupon->products()->attach($restrictedProduct->id);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'RESTRICT']);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // Cart Deletion
    // =========================================================================

    /** @test */
    public function delete_last_cart_item_clears_coupon(): void
    {
        $this->authUser();
        $cart = $this->createCartWithItem();
        $this->createCoupon('TEST10');

        $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'TEST10']);

        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'coupon' => 'TEST10',
        ]);

        $itemId = $cart->items()->first()->id;
        $this->deleteJson(self::PREFIX . "/cart/delete-item/{$itemId}");

        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'coupon' => null,
        ]);
    }

    /** @test */
    public function delete_cart_with_coupon_returns_warning(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $this->createCoupon('TEST10');

        $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'TEST10']);

        $response = $this->deleteJson(self::PREFIX . '/cart/delete-items');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', __('message.MESSAGE.COUPON_DELETE_CART_WARNING'));
    }

    /** @test */
    public function delete_cart_with_coupon_and_confirm_deletes(): void
    {
        $this->authUser();
        $cart = $this->createCartWithItem();
        $this->createCoupon('TEST10');

        $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'TEST10']);

        $response = $this->deleteJson(self::PREFIX . '/cart/delete-items?confirm=1');

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'coupon' => null,
            'total_price' => 0,
        ]);

        $this->assertEquals(0, $cart->items()->count());
    }

    // =========================================================================
    // Checkout - Coupon Re-validation
    // =========================================================================

    /** @test */
    public function checkout_with_expired_coupon_removes_it(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $coupon = $this->createCoupon('WILLEXPIRE');

        $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'WILLEXPIRE']);

        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'coupon' => 'WILLEXPIRE',
        ]);

        $coupon->update([
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonth(),
        ]);

        $orderService = app(OrderService::class);
        $request = \Illuminate\Http\Request::create('/dummy', 'POST', [
            'name' => 'Test',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
        ]);
        $request->setUserResolver(fn () => $this->user);

        $orderService->addItemsInOrder($request);

        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'coupon' => null,
        ]);
    }

    /** @test */
    public function checkout_records_coupon_usage(): void
    {
        $coupon = $this->createCoupon('COUPUSAGE');
        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test Order',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => json_encode(['street' => '123 Main St']),
            'total_price' => 80.00,
            'price' => 90.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => 'pending',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 80,
            'invoice_id' => 'INV-COUPON-1',
        ]);

        $orderService = app(OrderService::class);
        $orderService->markCodAsPaid($order);

        $this->assertDatabaseHas('coupon_usages', [
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
            'order_id' => $order->id,
        ]);
    }

    /** @test */
    public function checkout_does_not_duplicate_coupon_usage(): void
    {
        $coupon = $this->createCoupon('NODUP');
        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test Order',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => json_encode(['street' => '123 Main St']),
            'total_price' => 80.00,
            'price' => 90.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => 'pending',
        ]);

        $orderService = app(OrderService::class);
        $orderService->changeOrderStatus(null, 'completed', $order->id);
        $orderService->changeOrderStatus(null, 'completed', $order->id);

        $count = CouponUsage::where('coupon_id', $coupon->id)
            ->where('user_id', $this->user->id)
            ->count();

        $this->assertEquals(1, $count);
    }

    // =========================================================================
    // FREE_SHIPPING Coupon
    // =========================================================================

    /** @test */
    public function apply_free_shipping_coupon_success(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $this->createCoupon('FREESHIP', [
            'discount_type' => 'free_shipping',
            'discount' => 0,
        ]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'FREESHIP',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'coupon' => 'FREESHIP',
        ]);
    }

    /** @test */
    public function checkout_with_free_shipping_coupon_sets_shipping_to_zero(): void
    {
        $this->authUser();
        $cart = $this->createCartWithItem();
        $governorate = $this->createCountryAndGovernorate();
        $this->createCoupon('FREESHIP', [
            'discount_type' => 'free_shipping',
            'discount' => 0,
        ]);

        $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'FREESHIP']);

        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'coupon' => 'FREESHIP',
        ]);

        $orderService = app(OrderService::class);
        $request = \Illuminate\Http\Request::create('/dummy', 'POST', [
            'name' => 'Test',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'governorate_id' => $governorate->id,
        ]);
        $request->setUserResolver(fn () => $this->user);

        $order = $orderService->addItemsInOrder($request);

        $this->assertNotNull($order);
        $this->assertEquals(0.0, (float) $order->shipping_price);
    }

    /** @test */
    public function free_shipping_coupon_overrides_free_shipping_over_threshold(): void
    {
        $this->authUser();
        $cart = $this->createCartWithItem();
        $governorate = $this->createCountryAndGovernorate();
        $this->createCoupon('FREESHIP', [
            'discount_type' => 'free_shipping',
            'discount' => 0,
        ]);

        $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'FREESHIP']);

        $governorate->shippingPrice()->update(['free_shipping_over' => 500]);
        $cart->items()->first()->update(['total_price' => 50]);

        $orderService = app(OrderService::class);
        $request = \Illuminate\Http\Request::create('/dummy', 'POST', [
            'name' => 'Test',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'governorate_id' => $governorate->id,
        ]);
        $request->setUserResolver(fn () => $this->user);

        $order = $orderService->addItemsInOrder($request);

        $this->assertNotNull($order);
        $this->assertEquals(0.0, (float) $order->shipping_price);
    }

    /** @test */
    public function coupon_used_count_increments_on_first_usage(): void
    {
        $coupon = $this->createCoupon('INCREMENT', ['used' => 0]);
        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test Order',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => json_encode(['street' => '123 Main St']),
            'total_price' => 80.00,
            'price' => 90.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => 'pending',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 80,
            'invoice_id' => 'INV-INCR-1',
        ]);

        $orderService = app(OrderService::class);
        $orderService->markCodAsPaid($order);

        $this->assertDatabaseHas('coupons', [
            'id' => $coupon->id,
            'used' => 1,
        ]);
    }
}

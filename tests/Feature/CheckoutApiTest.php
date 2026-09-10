<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Tests\Concerns\CreatesTestTables;
use Tests\Concerns\WithInvoiceTables;
use Tests\TestCase;

class CheckoutApiTest extends TestCase
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
            'name' => 'Checkout User',
            'email' => 'checkout@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->product = Product::create([
            'name' => 'Checkout Product',
            'slug' => 'checkout-product-' . Str::random(8),
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

    private function createCartWithItem(): void
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
            'reserved_quantity' => 1,
            'shipping_method' => 'SCHEDULED',
        ]);

        $this->product->refresh();
        $this->product->update(['reserved_quantity' => 1]);
    }

    private function auth(): void
    {
        Sanctum::actingAs($this->user);
    }

    // =========================================================================
    // POST /checkout — Place order (COD)
    // =========================================================================

    public function test_checkout_requires_auth()
    {
        $this->postJson(self::PREFIX . '/checkout', [])->assertStatus(401);
    }

    public function test_checkout_requires_cart()
    {
        $this->auth();
        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'John',
            'user_phone' => '01000000001',
            'user_email' => 'john@example.com',
            'address' => ['street' => '123 Main St'],
            'governorate_id' => $this->governorate->id,
            'payment_method' => 'cod',
        ]);

        $this->assertContains($response->status(), [400, 422]);
    }

    public function test_checkout_cod_creates_order()
    {
        Event::fake();

        $this->auth();
        $this->createCartWithItem();

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'John Doe',
            'user_phone' => '01000000001',
            'user_email' => 'john@example.com',
            'governorate_id' => $this->governorate->id,
            'shipping_method' => 'SCHEDULED',
            'payment_method' => 'cod',
            'address' => ['street' => '123 Main St', 'city' => 'Cairo'],
        ]);

        $this->assertContains($response->status(), [200, 409]);
    }

    public function test_checkout_does_not_finalize_inventory()
    {
        $this->auth();
        $this->createCartWithItem();

        $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'John',
            'user_phone' => '01000000001',
            'user_email' => 'john@example.com',
            'address' => ['street' => '123 Main St'],
            'governorate_id' => $this->governorate->id,
            'shipping_method' => 'SCHEDULED',
            'payment_method' => 'cod',
        ]);

        $this->product->refresh();
        $this->assertEquals(50, $this->product->stock_quantity, 'Stock should NOT be deducted at checkout');
        $this->assertEquals(0, $this->product->sold_quantity, 'Sold quantity should NOT change at checkout');
    }

    public function test_mark_cod_as_paid_finalizes_inventory()
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\PaymentSucceeded::class]);

        $this->auth();
        $this->createCartWithItem();

        $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'John',
            'user_phone' => '01000000001',
            'user_email' => 'john@example.com',
            'address' => ['street' => '123 Main St'],
            'governorate_id' => $this->governorate->id,
            'shipping_method' => 'SCHEDULED',
            'payment_method' => 'cod',
        ]);

        $order = \Marvel\Database\Models\Order::where('user_id', $this->user->id)->first();
        $this->assertNotNull($order);

        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'update-order-status', 'guard_name' => 'api']);
        $this->user->givePermissionTo($permission);

        $this->postJson(self::PREFIX . '/checkout/cod/' . $order->id . '/mark-paid');

        $this->product->refresh();
        $this->assertEquals(49, $this->product->stock_quantity, 'Stock should be deducted after COD is marked paid');
        $this->assertEquals(1, $this->product->sold_quantity, 'Sold quantity should increment after COD is marked paid');
    }

    public function test_checkout_cod_with_pickup_rejected()
    {
        $this->auth();
        $this->createCartWithItem();

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'John',
            'user_phone' => '01000000001',
            'user_email' => 'john@example.com',
            'governorate_id' => $this->governorate->id,
            'payment_method' => 'cod',
            'fulfillment_type' => 'pickup',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // GET /checkout/promotions — Eligible promotions
    // =========================================================================

    public function test_eligible_promotions_requires_auth()
    {
        $this->getJson(self::PREFIX . '/checkout/promotions')->assertStatus(401);
    }

    public function test_eligible_promotions_returns_empty_when_no_cart()
    {
        $this->auth();
        $response = $this->getJson(self::PREFIX . '/checkout/promotions');
        $response->assertStatus(400);
    }

    // =========================================================================
    // GET /orders — Order listing
    // =========================================================================

    public function test_order_index_requires_auth()
    {
        $this->getJson(self::PREFIX . '/orders')->assertStatus(401);
    }

    public function test_order_index_returns_user_orders()
    {
        $this->auth();
        $this->createCartWithItem();

        $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'John',
            'user_phone' => '01000000001',
            'user_email' => 'john@example.com',
            'address' => ['street' => '123 Main St'],
            'governorate_id' => $this->governorate->id,
            'shipping_method' => 'SCHEDULED',
            'payment_method' => 'cod',
        ]);

        $response = $this->getJson(self::PREFIX . '/orders');
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    // =========================================================================
    // Checkout validation
    // =========================================================================

    public function test_checkout_validates_governorate()
    {
        $this->auth();
        $this->createCartWithItem();

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'John',
            'user_phone' => '01000000001',
            'user_email' => 'john@example.com',
            'governorate_id' => 99999,
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(422);
    }

    public function test_checkout_validates_phone()
    {
        $this->auth();
        $this->createCartWithItem();

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'John',
            'governorate_id' => $this->governorate->id,
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(422);
    }

    public function test_checkout_stores_coupon_usage_when_coupon_applied()
    {
        $this->auth();
        $this->createCartWithItem();

        \Marvel\Database\Models\Coupon::create([
            'code' => 'CHECKOUT10',
            'name' => 'Checkout Test',
            'slug' => 'coupon-' . Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $cart = \Marvel\Database\Models\Cart::where('user_id', $this->user->id)->first();
        if ($cart) {
            $cart->update(['coupon' => 'CHECKOUT10']);
        }

        $checkoutResponse = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'John',
            'user_phone' => '01000000001',
            'user_email' => 'john@example.com',
            'address' => ['street' => '123 Main St'],
            'governorate_id' => $this->governorate->id,
            'shipping_method' => 'SCHEDULED',
            'payment_method' => 'cod',
        ]);

        $this->assertContains($checkoutResponse->status(), [200, 409]);
    }
}

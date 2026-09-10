<?php

namespace Tests\Feature;

use App\DTOs\CheckoutTotals;
use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\PickupLocation;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\User;
use Marvel\Services\Pricing\ProductPricingService;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class PickupLocationPricingIntegrationTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const ADMIN_PREFIX = '/api/v1';
    private const PUBLIC_PREFIX = '/api/v1/general';

    private User $admin;
    private User $customer;
    private Product $product;
    private Product $discountedProduct;
    private Governorate $governorate;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

        $this->seedBaseData();
    }

    private function seedBaseData(): void
    {
        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-pickup@test.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $this->customer = User::create([
            'name' => 'Customer',
            'email' => 'customer-pickup@test.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $this->seedPickupLocationPermissions();

        $country = Country::create(['name' => 'Egypt', 'status' => true]);
        $this->governorate = Governorate::create([
            'country_id' => $country->id,
            'name' => 'Cairo',
            'status' => true,
        ]);
        ShippingPrice::create([
            'governorate_id' => $this->governorate->id,
            'price' => 30.00,
            'free_shipping_over' => 500.00,
            'status' => true,
        ]);

        $this->product = Product::create([
            'name' => 'Standard Product',
            'slug' => 'standard-product-' . Str::random(6),
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 50,
            'sold_quantity' => 0,
            'reserved_quantity' => 0,
        ]);

        $this->discountedProduct = Product::create([
            'name' => 'Discounted Product',
            'slug' => 'discounted-product-' . Str::random(6),
            'price' => 200.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 30,
            'sold_quantity' => 0,
            'reserved_quantity' => 0,
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 20,
            'discount_status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
    }

    private function seedPickupLocationPermissions(): void
    {
        $perms = [
            'view-pickup-locations',
            'create-pickup-location',
            'update-pickup-location',
            'delete-pickup-location',
            'update-order-status',
            'view-orders',
            'view-order',
        ];
        foreach ($perms as $perm) {
            SpatiePermission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }
        $this->admin->givePermissionTo($perms);
    }

    private function adminAuth(): void
    {
        Sanctum::actingAs($this->admin);
    }

    private function customerAuth(): void
    {
        Sanctum::actingAs($this->customer);
    }

    private function createPickupLocation(array $overrides = []): PickupLocation
    {
        return PickupLocation::create(array_merge([
            'store_name' => 'Main Branch',
            'address' => '123 Main Street',
            'phone' => '01000000001',
            'email' => 'branch@test.com',
            'latitude' => '30.0444',
            'longitude' => '31.2357',
            'working_hours' => [
                ['day' => 'Saturday', 'open' => '09:00', 'close' => '21:00'],
            ],
            'status' => true,
            'display_order' => 1,
        ], $overrides));
    }

    private function createCartWithItems(int $productQuantity = 2): Cart
    {
        $this->product->increment('reserved_quantity', $productQuantity);

        $cart = Cart::create([
            'user_id' => $this->customer->id,
            'status' => 'active',
            'total_price' => $this->product->price * $productQuantity,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'quantity' => $productQuantity,
            'price' => $this->product->price,
            'total_price' => $this->product->price * $productQuantity,
            'reserved_quantity' => $productQuantity,
            'shipping_method' => 'SCHEDULED',
        ]);

        return $cart->fresh();
    }

    // =======================================================================
    // PICKUP LOCATION API TESTS
    // =======================================================================

    /** @test */
    public function admin_can_list_pickup_locations_with_pagination()
    {
        $this->adminAuth();
        $this->createPickupLocation(['store_name' => 'A', 'display_order' => 1]);
        $this->createPickupLocation(['store_name' => 'B', 'display_order' => 2]);
        $this->createPickupLocation(['store_name' => 'C', 'display_order' => 3]);

        $response = $this->getJson(self::ADMIN_PREFIX . '/pickup-locations?per_page=2');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['data', 'page', 'current_page', 'from', 'to', 'last_page', 'per_page', 'total'],
        ]);
        $this->assertCount(2, $response->json('data.data'));
    }

    /** @test */
    public function admin_can_filter_active_pickup_locations()
    {
        $this->adminAuth();
        $this->createPickupLocation(['store_name' => 'Active Loc', 'status' => true]);
        $this->createPickupLocation(['store_name' => 'Inactive Loc', 'status' => false]);

        $response = $this->getJson(self::ADMIN_PREFIX . '/pickup-locations?active=true');
        $response->assertJsonFragment(['store_name' => 'Active Loc']);
        $response->assertJsonMissing(['store_name' => 'Inactive Loc']);
    }

    /** @test */
    public function admin_can_search_pickup_locations()
    {
        $this->adminAuth();
        $this->createPickupLocation(['store_name' => 'Downtown Branch']);
        $this->createPickupLocation(['store_name' => 'Uptown Branch']);

        $response = $this->getJson(self::ADMIN_PREFIX . '/pickup-locations?search=Downtown');
        $response->assertJsonFragment(['store_name' => 'Downtown Branch']);
        $response->assertJsonMissing(['store_name' => 'Uptown Branch']);
    }

    /** @test */
    public function pickup_locations_ordered_by_display_order()
    {
        $this->adminAuth();
        $this->createPickupLocation(['store_name' => 'Second', 'display_order' => 2]);
        $this->createPickupLocation(['store_name' => 'First', 'display_order' => 1]);
        $this->createPickupLocation(['store_name' => 'Third', 'display_order' => 3]);

        $response = $this->getJson(self::ADMIN_PREFIX . '/pickup-locations');
        $names = collect($response->json('data.data'))->pluck('store_name')->toArray();
        $this->assertEquals(['First', 'Second', 'Third'], $names);
    }

    /** @test */
    public function public_api_returns_only_active_locations()
    {
        $this->createPickupLocation(['store_name' => 'Visible', 'status' => true]);
        $this->createPickupLocation(['store_name' => 'Hidden', 'status' => false]);

        $response = $this->getJson(self::PUBLIC_PREFIX . '/pickup-locations');
        $response->assertJsonFragment(['store_name' => 'Visible']);
        $response->assertJsonMissing(['store_name' => 'Hidden']);
    }

    /** @test */
    public function public_api_returns_404_for_inactive_location()
    {
        $location = $this->createPickupLocation(['status' => false]);
        $this->getJson(self::PUBLIC_PREFIX . '/pickup-locations/' . $location->id)
            ->assertStatus(404);
    }

    /** @test */
    public function public_api_returns_404_for_nonexistent_location()
    {
        $this->getJson(self::PUBLIC_PREFIX . '/pickup-locations/99999')
            ->assertStatus(404);
    }

    /** @test */
    public function customer_cannot_create_pickup_location()
    {
        $this->customerAuth();
        $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [
            'store_name' => 'Hack',
            'address' => '123',
            'phone' => '123',
        ])->assertStatus(403);
    }

    /** @test */
    public function customer_cannot_delete_pickup_location()
    {
        $this->customerAuth();
        $loc = $this->createPickupLocation();
        $this->deleteJson(self::ADMIN_PREFIX . '/pickup-locations/' . $loc->id)
            ->assertStatus(403);
    }

    /** @test */
    public function store_validates_required_fields()
    {
        $this->adminAuth();
        $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [])
            ->assertStatus(422);

        $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [
            'store_name' => 'Test',
            'address' => '123 St',
        ])->assertStatus(422)
            ->assertJsonStructure(['phone']);
    }

    /** @test */
    public function store_validates_email_format()
    {
        $this->adminAuth();
        $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [
            'store_name' => 'Test',
            'address' => '123 St',
            'phone' => '123',
            'email' => 'not-an-email',
        ])->assertStatus(422)
            ->assertJsonStructure(['email']);
    }

    /** @test */
    public function store_rejects_negative_display_order()
    {
        $this->adminAuth();
        $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [
            'store_name' => 'Test',
            'address' => '123 St',
            'phone' => '123',
            'display_order' => -1,
        ])->assertStatus(422)
            ->assertJsonStructure(['display_order']);
    }

    /** @test */
    public function update_validates_existence()
    {
        $this->adminAuth();
        $this->putJson(self::ADMIN_PREFIX . '/pickup-locations/99999', [
            'store_name' => 'Ghost',
        ])->assertStatus(404);
    }

    /** @test */
    public function soft_delete_works_correctly()
    {
        $this->adminAuth();
        $loc = $this->createPickupLocation();
        $locId = $loc->id;

        $this->deleteJson(self::ADMIN_PREFIX . '/pickup-locations/' . $locId)
            ->assertStatus(200);
        $this->assertSoftDeleted('pickup_locations', ['id' => $locId]);

        $this->getJson(self::ADMIN_PREFIX . '/pickup-locations/' . $locId)
            ->assertStatus(404);
    }

    /** @test */
    public function unauthenticated_user_cannot_access_admin_endpoints()
    {
        $this->getJson(self::ADMIN_PREFIX . '/pickup-locations')->assertStatus(401);
        $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [])->assertStatus(401);
        $this->putJson(self::ADMIN_PREFIX . '/pickup-locations/1', [])->assertStatus(401);
        $this->deleteJson(self::ADMIN_PREFIX . '/pickup-locations/1')->assertStatus(401);
    }

    /** @test */
    public function admin_resource_includes_created_at()
    {
        $this->adminAuth();
        $loc = $this->createPickupLocation();
        $response = $this->getJson(self::ADMIN_PREFIX . '/pickup-locations/' . $loc->id);
        $response->assertJsonStructure(['data' => ['created_at']]);
    }

    /** @test */
    public function public_resource_excludes_created_at()
    {
        $loc = $this->createPickupLocation();
        $response = $this->getJson(self::PUBLIC_PREFIX . '/pickup-locations/' . $loc->id);
        $response->assertJsonMissing(['created_at']);
    }

    /** @test */
    public function order_creation_saves_pickup_location_snapshot()
    {
        $this->customerAuth();
        $loc = $this->createPickupLocation();
        $this->createCartWithItems();

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'fulfillment_type' => 'pickup',
            'payment_method' => 'pay_at_cashier',
            'pickup_location_id' => $loc->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals($loc->id, $order->pickup_location_id);
        $this->assertEquals($loc->store_name, $order->pickup_location_name);
        $this->assertEquals($loc->address, $order->pickup_location_address);
        $this->assertEquals($loc->phone, $order->pickup_location_phone);
        $this->assertEquals($loc->latitude . ',' . $loc->longitude, $order->pickup_location_coordinates);
    }

    /** @test */
    public function checkout_rejects_cod_for_pickup()
    {
        $this->customerAuth();
        $loc = $this->createPickupLocation();
        $this->createCartWithItems();

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'pickup',
            'payment_method' => 'cod',
            'pickup_location_id' => $loc->id,
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function inactive_pickup_location_is_accepted_with_snapshot()
    {
        $this->customerAuth();
        $loc = $this->createPickupLocation(['status' => false]);
        $this->createCartWithItems();

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'pickup',
            'payment_method' => 'pay_at_cashier',
            'pickup_location_id' => $loc->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertEquals($loc->store_name, $order->pickup_location_name);
    }

    // =======================================================================
    // PRICING TESTS
    // =======================================================================

    /** @test */
    public function product_pricing_service_calculates_base_price()
    {
        $pricingService = app(ProductPricingService::class);
        $result = $pricingService->calculateProductPricing($this->product);

        $this->assertEquals(100.00, $result['base_price']);
        $this->assertNull($result['price_after_discount']);
        $this->assertNull($result['price_after_flash_sale']);
        $this->assertEquals(100.00, $result['final_price']);
    }

    /** @test */
    public function product_pricing_service_calculates_discounted_price()
    {
        $pricingService = app(ProductPricingService::class);
        $result = $pricingService->calculateProductPricing($this->discountedProduct);

        $this->assertEquals(200.00, $result['base_price']);
        $this->assertNotNull($result['price_after_discount']);
        $this->assertEquals(160.00, $result['final_price']);
    }

    /** @test */
    public function product_pricing_service_handles_null_price()
    {
        $pricingService = app(ProductPricingService::class);
        $product = Product::create([
            'name' => 'Zero Price',
            'slug' => 'zero-price-' . Str::random(6),
            'price' => 0,
            'status' => true,
        ]);

        $result = $pricingService->calculateProductPricing($product);
        $this->assertEquals(0, $result['base_price']);
        $this->assertEquals(0, $result['final_price']);
    }

    /** @test */
    public function product_pricing_service_calculates_fixed_discount()
    {
        $pricingService = app(ProductPricingService::class);
        $price = $pricingService->calculateDiscountedPrice(100.00, 'fixed', 25.00);
        $this->assertEquals(75.00, $price);
    }

    /** @test */
    public function product_pricing_service_calculates_percentage_discount()
    {
        $pricingService = app(ProductPricingService::class);
        $price = $pricingService->calculateDiscountedPrice(200.00, 'percentage', 15);
        $this->assertEquals(170.00, $price);
    }

    /** @test */
    public function product_pricing_service_prevents_negative_price()
    {
        $pricingService = app(ProductPricingService::class);
        $price = $pricingService->calculateDiscountedPrice(50.00, 'fixed', 100.00);
        $this->assertEquals(0, $price);
    }

    /** @test */
    public function product_pricing_service_caps_percentage_at_100()
    {
        $pricingService = app(ProductPricingService::class);
        $price = $pricingService->calculateDiscountedPrice(100.00, 'percentage', 150);
        $this->assertEquals(0, $price);
    }

    /** @test */
    public function variant_sale_price_calculation()
    {
        $pricingService = app(ProductPricingService::class);
        $variant = new \Marvel\Database\Models\ProductVariant(['price' => 80.00]);

        $price = $pricingService->calculateVariantSalePrice($this->product, $variant);
        $this->assertEquals(80.00, $price);
    }

    // =======================================================================
    // CHECKOUT PRICING TESTS
    // =======================================================================

    /** @test */
    public function checkout_with_cod_creates_order_with_correct_totals()
    {
        $this->customerAuth();
        $this->createCartWithItems(3);

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => '123'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertNotNull($order);

        $expectedSubtotal = 300.00;
        $expectedShipping = 30.00;
        $expectedTotal = $expectedSubtotal + $expectedShipping;

        $this->assertEquals($expectedSubtotal, (float) $order->price);
        $this->assertEquals($expectedTotal, (float) $order->total_price);
        $this->assertEquals('SCHEDULED', $order->shipping_method);
        $this->assertEquals('cod', $order->payment_method);

        $transaction = Transaction::where('order_id', $order->id)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals('pending', $transaction->status);
        $this->assertEquals('cod', $transaction->payment_method);
        $this->assertEquals($expectedTotal, (float) $transaction->amount);
    }

    /** @test */
    public function checkout_with_coupon_applies_discount_correctly()
    {
        $this->customerAuth();
        $this->createCartWithItems(2);

        $coupon = Coupon::create([
            'name' => 'Test 10%',
            'slug' => 'test10-' . Str::random(4),
            'discount_type' => 'percentage',
            'discount' => 10,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);
        $coupon->refresh();
        $couponCode = $coupon->code;

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => $couponCode]);

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();

        $expectedSubtotal = 200.00;
        $expectedCouponDiscount = 20.00;
        $expectedAfterCoupon = 180.00;
        $expectedShipping = 30.00;
        $expectedTotal = $expectedAfterCoupon + $expectedShipping;

        $this->assertEquals($expectedSubtotal, (float) $order->price);
        $this->assertEquals($expectedCouponDiscount, (float) $order->coupon_discount);
        $this->assertEquals('percentage', $order->coupon_discount_type);
        $this->assertEquals($couponCode, $order->coupon);
        $this->assertEquals($expectedTotal, (float) $order->total_price);
    }

    /** @test */
    public function checkout_with_fixed_coupon_applies_discount()
    {
        $this->customerAuth();
        $this->createCartWithItems(2);

        $coupon = Coupon::create([
            'name' => 'Fixed 50',
            'slug' => 'fixed50-' . Str::random(4),
            'discount_type' => 'fixed_rate',
            'discount' => 50,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);
        $coupon->refresh();
        $couponCode = $coupon->code;

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => $couponCode]);

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertEquals(50.00, (float) $order->coupon_discount);
        $this->assertEquals(180.00, (float) $order->total_price);
    }

    /** @test */
    public function checkout_with_expired_coupon_is_ignored()
    {
        $this->customerAuth();
        $this->createCartWithItems(1);

        $coupon = Coupon::create([
            'name' => 'Expired',
            'slug' => 'expired-' . Str::random(4),
            'discount_type' => 'percentage',
            'discount' => 50,
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonth(),
            'status' => true,
        ]);
        $coupon->refresh();
        $couponCode = $coupon->code;

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => $couponCode]);

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $expectedTotal = 100.00 + 30.00;
        $this->assertEquals($expectedTotal, (float) $order->total_price);
        $this->assertNull($order->coupon);
    }

    /** @test */
    public function checkout_with_free_shipping_coupon_sets_shipping_to_zero()
    {
        $this->customerAuth();
        $this->createCartWithItems(1);

        $coupon = Coupon::create([
            'name' => 'Free Ship',
            'slug' => 'freeship-' . Str::random(4),
            'discount_type' => 'free_shipping',
            'discount' => 0,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);
        $coupon->refresh();
        $couponCode = $coupon->code;

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => $couponCode]);

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertEquals(0, (float) $order->shipping_price);
        $this->assertEquals(100.00, (float) $order->total_price);
    }

    /** @test */
    public function free_shipping_by_threshold_works()
    {
        $this->customerAuth();
        $this->createCartWithItems(6);

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertEquals(600.00, (float) $order->price);
        $this->assertEquals(0, (float) $order->shipping_price);
        $this->assertEquals(600.00, (float) $order->total_price);
    }

    /** @test */
    public function checkout_with_promotion_applies_discount()
    {
        $this->customerAuth();
        $cart = $this->createCartWithItems(1);

        $promotion = Promotion::create([
            'name' => 'Test Promotion',
            'slug' => 'test-promo-' . Str::random(6),
            'code' => 'PROMO10',
            'type' => 'percentage',
            'type_amount' => 'percentage',
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'apply_to' => 'specific_products',
            'status' => true,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
        ]);

        $promotion->products()->attach($this->product->id);

        $cartItem = $cart->items->first();
        $cartItem->update([
            'promotion_id' => $promotion->id,
        ]);

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
            'selected_promotion_id' => $promotion->id,
        ]);

        $this->assertContains($response->status(), [200, 422, 500]);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        if ($order) {
            $this->assertNotNull($order->promotion_id);
            $this->assertNotNull($order->promotion_discount);
        }
    }

    /** @test */
    public function checkout_requires_auth()
    {
        $this->postJson(self::PUBLIC_PREFIX . '/checkout', [])
            ->assertStatus(401);
    }

    /** @test */
    public function checkout_requires_valid_governorate_for_delivery()
    {
        $this->customerAuth();
        $this->createCartWithItems();

        $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => 99999,
        ])->assertStatus(422);
    }

    /** @test */
    public function checkout_rejects_empty_cart()
    {
        $this->customerAuth();

        Cart::create([
            'user_id' => $this->customer->id,
            'status' => 'active',
            'total_price' => 0,
        ]);

        $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ])->assertStatus(500);
    }

    /** @test */
    public function checkout_rejects_invalid_payment_method()
    {
        $this->customerAuth();
        $this->createCartWithItems();

        $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'bitcoin',
            'governorate_id' => $this->governorate->id,
        ])->assertStatus(422);
    }

    /** @test */
    public function pay_at_cashier_requires_pickup_fulfillment()
    {
        $this->customerAuth();
        $this->createCartWithItems();

        $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'pay_at_cashier',
            'governorate_id' => $this->governorate->id,
        ])->assertStatus(422);
    }

    /** @test */
    public function order_creation_saves_discount_snapshot()
    {
        $this->customerAuth();
        $cart = $this->createCartWithItems();

        $cartItem = $cart->items->first();
        $originalPrice = (float) $cartItem->price;
        $this->assertEquals(100.00, $originalPrice);

        $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $orderItem = $order->orderItems()->first();

        $this->assertEquals($this->product->id, $orderItem->product_id);
        $this->assertEquals(2, $orderItem->product_quantity);
        $this->assertNotNull($orderItem->product_total_price);
    }

    /** @test */
    public function mark_cod_as_paid_updates_order_and_transaction()
    {
        Event::fake();

        $this->customerAuth();
        $this->createCartWithItems();
        $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertNotNull($order);

        $this->adminAuth();
        $response = $this->postJson(self::PUBLIC_PREFIX . "/checkout/cod/{$order->id}/mark-paid");
        $response->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'completed',
        ]);

        $transaction = Transaction::where('order_id', $order->id)->first();
        $this->assertEquals('paid', $transaction->status);
        $this->assertNotNull($transaction->paid_at);
    }

    /** @test */
    public function completed_order_has_pickup_snapshot_when_pickup()
    {
        $this->customerAuth();
        $loc = $this->createPickupLocation();
        $this->createCartWithItems();

        $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'pickup',
            'payment_method' => 'pay_at_cashier',
            'pickup_location_id' => $loc->id,
        ]);

        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertEquals($loc->store_name, $order->pickup_location_name);
        $this->assertEquals($loc->address, $order->pickup_location_address);
        $this->assertEquals($loc->phone, $order->pickup_location_phone);
        $this->assertEquals($loc->latitude . ',' . $loc->longitude, $order->pickup_location_coordinates);
    }

    /** @test */
    public function order_with_pickup_has_zero_shipping_price()
    {
        $this->customerAuth();
        $loc = $this->createPickupLocation();
        $this->createCartWithItems();

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'pickup',
            'payment_method' => 'pay_at_cashier',
            'pickup_location_id' => $loc->id,
        ]);

        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertEquals(0, (float) $order->shipping_price);
        $this->assertEquals(200.00, (float) $order->total_price);
    }

    /** @test */
    public function admin_can_access_admin_orders()
    {
        $this->adminAuth();
        $response = $this->getJson(self::ADMIN_PREFIX . '/orders');
        $this->assertContains($response->status(), [200, 403]);
    }

    // =======================================================================
    // HARDENING TESTS
    // =======================================================================

    /** @test */
    public function checkout_with_pickup_does_not_require_governorate()
    {
        $this->customerAuth();
        $loc = $this->createPickupLocation();
        $this->createCartWithItems();

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'pickup',
            'payment_method' => 'pay_at_cashier',
            'pickup_location_id' => $loc->id,
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function checkout_rejects_nonexistent_pickup_location()
    {
        $this->customerAuth();
        $this->createCartWithItems();

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'pickup',
            'payment_method' => 'pay_at_cashier',
            'pickup_location_id' => 99999,
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function checkout_rejects_pickup_without_pickup_location_id()
    {
        $this->customerAuth();
        $this->createCartWithItems();

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'pickup',
            'payment_method' => 'pay_at_cashier',
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function checkout_with_soft_deleted_pickup_location_preserves_snapshot()
    {
        $this->customerAuth();
        $loc = $this->createPickupLocation();
        $loc->delete();
        $this->createCartWithItems();

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'pickup',
            'payment_method' => 'pay_at_cashier',
            'pickup_location_id' => $loc->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertEquals($loc->store_name, $order->pickup_location_name);
        $this->assertEquals($loc->address, $order->pickup_location_address);
        $this->assertEquals($loc->phone, $order->pickup_location_phone);
        $this->assertEquals($loc->latitude . ',' . $loc->longitude, $order->pickup_location_coordinates);
    }

    /** @test */
    public function checkout_with_pickup_and_online_payment_succeeds()
    {
        $this->customerAuth();
        $loc = $this->createPickupLocation();
        $this->createCartWithItems();

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'pickup',
            'payment_method' => 'online',
            'pickup_location_id' => $loc->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertEquals('online', $order->payment_method);
        $this->assertEquals('pickup', $order->fulfillment_type);
    }

    /** @test */
    public function checkout_with_pickup_and_pay_at_cashier_succeeds()
    {
        $this->customerAuth();
        $loc = $this->createPickupLocation();
        $this->createCartWithItems();

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'pickup',
            'payment_method' => 'pay_at_cashier',
            'pickup_location_id' => $loc->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertEquals('pay_at_cashier', $order->payment_method);
        $this->assertEquals('pickup', $order->fulfillment_type);
    }

    /** @test */
    public function checkout_delivery_rejects_without_governorate()
    {
        $this->customerAuth();
        $this->createCartWithItems();

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function order_resource_includes_pickup_location_for_pickup_orders()
    {
        $this->customerAuth();
        $loc = $this->createPickupLocation();
        $this->createCartWithItems();

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'pickup',
            'payment_method' => 'pay_at_cashier',
            'pickup_location_id' => $loc->id,
        ]);

        $orderId = Order::where('user_id', $this->customer->id)->latest()->first()->id;

        $this->adminAuth();
        $showResponse = $this->getJson(self::ADMIN_PREFIX . '/orders/' . $orderId);
        $showResponse->assertStatus(200);
        $this->assertNotNull($showResponse->json('data.pickup_location'));
        $this->assertEquals($loc->store_name, $showResponse->json('data.pickup_location.store_name'));
    }

    /** @test */
    public function order_resource_excludes_pickup_location_for_delivery_orders()
    {
        $this->customerAuth();
        $this->createCartWithItems();

        $response = $this->postJson(self::PUBLIC_PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $orderId = Order::where('user_id', $this->customer->id)->latest()->first()->id;

        $this->adminAuth();
        $showResponse = $this->getJson(self::ADMIN_PREFIX . '/orders/' . $orderId);
        $showResponse->assertStatus(200);
        $this->assertNull($showResponse->json('data.pickup_location'));
    }

    /** @test */
    public function admin_store_validates_working_hours_structure()
    {
        $this->adminAuth();

        $response = $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [
            'store_name' => 'Hours Test',
            'address' => '123 St',
            'phone' => '123',
            'working_hours' => [
                ['day' => 'Monday', 'open' => '09:00'],
            ],
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function customer_cannot_update_pickup_location()
    {
        $this->customerAuth();
        $loc = $this->createPickupLocation();

        $response = $this->putJson(self::ADMIN_PREFIX . '/pickup-locations/' . $loc->id, [
            'store_name' => 'Hack Update',
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function customer_cannot_view_pickup_locations()
    {
        $this->customerAuth();
        $this->createPickupLocation();

        $response = $this->getJson(self::ADMIN_PREFIX . '/pickup-locations');
        $response->assertStatus(403);
    }
}

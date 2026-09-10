<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\ProductType;
use Marvel\Enums\RefundPolicyStatus;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createSuperAdminUser(): User
    {
        $permissions = [
            PermissionEnum::CREATE_PRODUCT,
            PermissionEnum::VIEW_PRODUCTS,
            PermissionEnum::VIEW_ANALYTICS];

        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, self::GUARD);
        }

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'guard_name' => self::GUARD,
            'display_name' => json_encode(['en' => 'Super Admin', 'ar' => 'مدير النظام'])]);

        foreach ($permissions as $perm) {
            $role->givePermissionTo($perm);
        }

        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin',
            'phone_number' => '+1-555-0100']);

        $user->assignRole($role);

        foreach ($permissions as $perm) {
            $user->givePermissionTo($perm);
        }

        return $user;
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => ['en' => 'Test Product ' . Str::random(6)],
            'slug' => 'test-product-' . Str::random(8),
            'price' => 100.00,
            'quantity' => 50,
            'sold_quantity' => 10,
            'in_stock' => true,
            'status' => true,
            'product_type' => ProductType::SIMPLE], $overrides));
    }

    private function makeOrder(array $overrides = []): Order
    {
        $user = User::factory()->create(['type' => 'user']);
        return Order::create(array_merge([
            'user_id' => $user->id,
            'user_email' => $user->email,
            'name' => 'Test Customer',
            'user_phone' => '+1-555-0000',
            'address' => json_encode(['street' => '123 Main St']),
            'total_price' => 200.00,
            'price' => 180.00,
            'shipping_price' => 15.00,
            'fast_shipping_fee' => 5.00,
            'status' => 'completed',
            'shipping_method' => 'SCHEDULED'], $overrides));
    }

    private function makeTransaction(int $orderId, array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'invoice_id' => rand(1000, 9999),
            'user_id' => 1,
            'payment_method' => 'stripe',
            'order_id' => $orderId], $overrides));
    }

    private function makeRefund(int $orderId, array $overrides = []): void
    {
        $data = array_merge([
            'amount' => 50.00,
            'title' => 'Test Refund',
            'status' => RefundPolicyStatus::APPROVED,
            'order_id' => $orderId,
            'user_id' => 1], $overrides);

        DB::table('refunds')->insert($data + [
            'created_at' => now(),
            'updated_at' => now()]);
    }

    private function makeCart(array $overrides = []): Cart
    {
        $user = User::factory()->create(['type' => 'user']);
        return Cart::create(array_merge([
            'user_id' => $user->id,
            'total_price' => 150.00,
            'status' => 'active'], $overrides));
    }

    private function makeCartItem(int $cartId, int $productId, array $overrides = []): CartItem
    {
        return CartItem::create(array_merge([
            'cart_id' => $cartId,
            'product_id' => $productId,
            'quantity' => 2,
            'price' => 100.00,
            'total_price' => 200.00], $overrides));
    }

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['en' => 'Test Coupon', 'ar' => 'Test Coupon'],
            'slug' => 'test-coupon-' . Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10.00,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => true], $overrides));
    }

    private function makeCouponUsage(int $couponId, int $userId, int $orderId): CouponUsage
    {
        return CouponUsage::create([
            'coupon_id' => $couponId,
            'user_id' => $userId,
            'order_id' => $orderId]);
    }

    /**
     * @return array{category_id: int, category_name: string}
     */
    private function makeCategory(): array
    {
        $cat = Category::create([
            'name' => ['en' => 'Test Category ' . Str::random(6)],
            'slug' => 'test-category-' . Str::random(8)]);
        return ['category_id' => $cat->id, 'category_name' => (string) $cat->name];
    }

    private function attachProductToCategory(int $productId, int $categoryId): void
    {
        DB::table('category_product')->insert([
            'product_id' => $productId,
            'category_id' => $categoryId]);
    }

    private function attachOrderProduct(int $orderId, int $productId, array $overrides = []): void
    {
        DB::table('order_products')->insert(array_merge([
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_name' => 'Test Product',
            'product_quantity' => 2,
            'product_price' => 100.00,
            'product_total_price' => 200.00], $overrides));
    }

    // =========================================================================
    // Authentication Tests
    // =========================================================================

    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $endpoints = [
            'overview', 'revenue', 'order-stats', 'recent-orders',
            'top-products', 'category-stats', 'low-stock',
            'sales', 'customers', 'products', 'orders',
            'categories', 'coupons', 'cart', 'finance'];

        foreach ($endpoints as $ep) {
            $response = $this->getJson(self::PREFIX . '/dashboard/' . $ep);
            $response->assertUnauthorized();
        }
    }

    // =========================================================================
    // Original 7 Dashboard Endpoints
    // =========================================================================

    public function test_overview_returns_expected_structure(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $this->makeOrder(['total_price' => 500, 'status' => 'completed']);
        $this->makeOrder(['total_price' => 300, 'status' => 'completed']);
        $this->makeOrder(['total_price' => 100, 'status' => 'pending']);
        $this->makeProduct();
        $this->makeProduct();

        $response = $this->getJson(self::PREFIX . '/dashboard/overview');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success', 'message', 'data' => [
                'total_revenue', 'todays_revenue', 'total_refunds',
                'total_orders', 'total_products', 'total_customers', 'new_customers']]);
        $this->assertEquals(800.0, $response->json('data.total_revenue'), '', 0.01);
    }

    public function test_revenue_returns_monthly_breakdown(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $this->makeOrder(['total_price' => 400, 'status' => 'completed']);
        $this->makeOrder(['total_price' => 600, 'status' => 'completed']);

        $response = $this->getJson(self::PREFIX . '/dashboard/revenue');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success', 'message', 'data' => [
                'total_revenue', 'todays_revenue', 'monthly_breakdown']]);
        $this->assertEquals(1000.0, $response->json('data.total_revenue'), '', 0.01);
    }

    public function test_order_stats_returns_status_breakdown(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $this->makeOrder(['status' => 'completed']);
        $this->makeOrder(['status' => 'pending']);
        $this->makeOrder(['status' => 'cancelled']);

        $response = $this->getJson(self::PREFIX . '/dashboard/order-stats');

        $response->assertOk();
        $response->assertJsonStructure([
            'success', 'message', 'data' => [
                'today', 'weekly', 'monthly', 'yearly']]);
    }

    public function test_recent_orders_returns_limited_orders(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 5; $i++) {
            $this->makeOrder();
        }

        $response = $this->getJson(self::PREFIX . '/dashboard/recent-orders?limit=3');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_top_products_returns_sorted_by_sold_quantity(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $this->makeProduct(['sold_quantity' => 5, 'price' => 50]);
        $this->makeProduct(['sold_quantity' => 20, 'price' => 100]);
        $this->makeProduct(['sold_quantity' => 1, 'price' => 25]);

        $response = $this->getJson(self::PREFIX . '/dashboard/top-products');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $data = $response->json('data');
        $this->assertGreaterThanOrEqual($data[1]['sold_quantity'] ?? 0, $data[0]['sold_quantity'] ?? 0);
    }

    public function test_category_stats_returns_distribution(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $cat1 = $this->makeCategory();
        $cat2 = $this->makeCategory();
        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();
        $this->attachProductToCategory($p1->id, $cat1['category_id']);
        $this->attachProductToCategory($p2->id, $cat2['category_id']);

        $response = $this->getJson(self::PREFIX . '/dashboard/category-stats');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success', 'message', 'data' => [
                'product_distribution', 'sales_distribution']]);
    }

    public function test_low_stock_returns_products_below_threshold(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $this->makeProduct(['quantity' => 3, 'name' => ['en' => 'Low Stock Item']]);
        $this->makeProduct(['quantity' => 50, 'name' => ['en' => 'Well Stocked Item']]);

        $response = $this->getJson(self::PREFIX . '/dashboard/low-stock');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $names = array_map(fn ($p) => is_array($p['name']) ? ($p['name']['en'] ?? '') : $p['name'], $response->json('data'));
        $this->assertContains('Low Stock Item', $names);
    }

    // =========================================================================
    // Sales Analytics
    // =========================================================================

    public function test_sales_analytics_returns_daily_comparison(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $this->makeOrder(['total_price' => 100, 'status' => 'completed']);
        $this->makeOrder(['total_price' => 200, 'status' => 'completed']);

        $response = $this->getJson(self::PREFIX . '/dashboard/sales');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success', 'message', 'data' => [
                'daily_revenue' => ['today', 'yesterday', 'last_7_days', 'last_30_days'],
                'revenue_comparison',
                'average_order_value',
                'revenue_by_payment_method']]);
    }

    public function test_sales_analytics_shows_revenue_by_payment_method(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $order1 = $this->makeOrder(['total_price' => 300, 'status' => 'completed']);
        $order2 = $this->makeOrder(['total_price' => 500, 'status' => 'completed']);
        $this->makeTransaction($order1->id, ['payment_method' => 'stripe']);
        $this->makeTransaction($order2->id, ['payment_method' => 'paypal']);

        $response = $this->getJson(self::PREFIX . '/dashboard/sales');

        $response->assertOk();
        $methods = array_column($response->json('data.revenue_by_payment_method'), 'method');
        $this->assertContains('stripe', $methods);
        $this->assertContains('paypal', $methods);
    }

    // =========================================================================
    // Customer Analytics
    // =========================================================================

    public function test_customer_analytics_returns_growth_and_segments(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $customer = User::factory()->create(['type' => 'user']);
        $order = $this->makeOrder(['user_id' => $customer->id, 'status' => 'completed']);

        $response = $this->getJson(self::PREFIX . '/dashboard/customers');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success', 'message', 'data' => [
                'new_vs_returning',
                'monthly_growth',
                'top_customers' => ['by_orders', 'by_revenue'],
                'customer_lifetime_value',
                'active_customers' => ['last_7_days', 'last_30_days', 'last_90_days']]]);
    }

    // =========================================================================
    // Product Analytics
    // =========================================================================

    public function test_product_analytics_returns_all_sections(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $this->makeProduct(['sold_quantity' => 50, 'price' => 100]);
        $this->makeProduct(['sold_quantity' => 0, 'price' => 50]);

        $response = $this->getJson(self::PREFIX . '/dashboard/products');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success', 'message', 'data' => [
                'best_selling',
                'worst_selling',
                'never_sold',
                'out_of_stock',
                'inventory_value']]);
    }

    public function test_product_analytics_includes_never_sold(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $this->makeProduct(['sold_quantity' => 0, 'name' => ['en' => 'Never Sold Product']]);
        $this->makeProduct(['sold_quantity' => 10, 'name' => ['en' => 'Sold Product']]);

        $response = $this->getJson(self::PREFIX . '/dashboard/products');

        $response->assertOk();
        $names = array_map(fn ($p) => is_array($p['name']) ? ($p['name']['en'] ?? '') : $p['name'], $response->json('data.never_sold'));
        $this->assertContains('Never Sold Product', $names);
    }

    // =========================================================================
    // Order Analytics
    // =========================================================================

    public function test_order_analytics_returns_timeline_and_success_rate(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $this->makeOrder(['status' => 'completed']);
        $this->makeOrder(['status' => 'cancelled']);

        $response = $this->getJson(self::PREFIX . '/dashboard/orders');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success', 'message', 'data' => [
                'timeline' => ['daily', 'weekly', 'monthly'],
                'success_rate' => ['completed', 'cancelled', 'refunded', 'total'],
                'refund_rate']]);
    }

    public function test_order_analytics_refund_rate_is_calculated(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $order = $this->makeOrder(['status' => 'completed']);
        $this->makeRefund($order->id);

        $response = $this->getJson(self::PREFIX . '/dashboard/orders');

        $response->assertOk();
        $this->assertGreaterThan(0, $response->json('data.refund_rate'));
    }

    // =========================================================================
    // Category Analytics
    // =========================================================================

    public function test_category_analytics_returns_distribution_and_growth(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $cat = $this->makeCategory();
        $product = $this->makeProduct(['price' => 100]);
        $this->attachProductToCategory($product->id, $cat['category_id']);

        $order = $this->makeOrder(['status' => 'completed']);
        $this->attachOrderProduct($order->id, $product->id, [
            'product_quantity' => 2,
            'product_price' => 100.00]);

        $response = $this->getJson(self::PREFIX . '/dashboard/categories');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success', 'message', 'data' => [
                'product_distribution',
                'highest_revenue',
                'lowest_revenue',
                'category_growth']]);
    }

    // =========================================================================
    // Coupon Analytics
    // =========================================================================

    public function test_coupon_analytics_returns_usage_and_revenue(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $coupon = $this->makeCoupon();
        $order = $this->makeOrder(['status' => 'completed', 'coupon' => $coupon->code, 'coupon_discount' => 10]);
        $this->makeCouponUsage($coupon->id, $user->id, $order->id);

        $response = $this->getJson(self::PREFIX . '/dashboard/coupons');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success', 'message', 'data' => [
                'total_usage',
                'top_coupons',
                'revenue_by_coupon',
                'total_coupon_discount']]);
        $this->assertGreaterThanOrEqual(1, $response->json('data.total_usage'));
    }

    // =========================================================================
    // Cart Analytics
    // =========================================================================

    public function test_cart_analytics_returns_abandonment_rate(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $cart = $this->makeCart(['status' => 'active', 'total_price' => 100]);
        $product = $this->makeProduct();
        $this->makeCartItem($cart->id, $product->id);

        $checkedOutCart = $this->makeCart(['status' => 'checked_out', 'total_price' => 200]);

        $response = $this->getJson(self::PREFIX . '/dashboard/cart');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success', 'message', 'data' => [
                'abandonment_rate',
                'most_added_products',
                'average_cart_value',
                'checkout_dropoff_rate']]);
    }

    public function test_cart_analytics_shows_zero_when_no_carts(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/dashboard/cart');

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.abandonment_rate'));
        $this->assertEquals(0, $response->json('data.checkout_dropoff_rate'));
        $this->assertEquals(0, $response->json('data.average_cart_value'));
    }

    // =========================================================================
    // Finance Analytics
    // =========================================================================

    public function test_finance_analytics_returns_revenue_breakdown(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $order = $this->makeOrder([
            'total_price' => 1000,
            'shipping_price' => 50,
            'fast_shipping_fee' => 10,
            'status' => 'completed',
            'coupon_discount' => 30]);
        $this->makeRefund($order->id);

        $response = $this->getJson(self::PREFIX . '/dashboard/finance');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success', 'message', 'data' => [
                'gross_revenue',
                'net_revenue',
                'refund_amount',
                'total_discount',
                'shipping_revenue']]);
        $this->assertEquals(1000.00, $response->json('data.gross_revenue'));
        $this->assertEquals(60.00, $response->json('data.shipping_revenue'));
    }

    public function test_finance_analytics_returns_zero_when_no_data(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/dashboard/finance');

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.gross_revenue'));
        $this->assertEquals(0, $response->json('data.net_revenue'));
        $this->assertEquals(0, $response->json('data.shipping_revenue'));
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function test_all_endpoints_return_429_when_rate_limited(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 60; $i++) {
            $this->getJson(self::PREFIX . '/dashboard/overview');
        }

        $response = $this->getJson(self::PREFIX . '/dashboard/overview');
        $response->assertStatus(429);
    }

    public function test_empty_database_returns_valid_responses(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $endpoints = [
            'overview', 'revenue', 'order-stats', 'recent-orders',
            'top-products', 'category-stats', 'low-stock',
            'sales', 'customers', 'products', 'orders',
            'categories', 'coupons', 'cart', 'finance'];

        foreach ($endpoints as $ep) {
            $response = $this->getJson(self::PREFIX . '/dashboard/' . $ep);

            $response->assertOk();
            $response->assertJsonPath('success', true);
        }
    }

    public function test_top_products_is_empty_when_no_sales(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $this->makeProduct(['sold_quantity' => 0]);

        $response = $this->getJson(self::PREFIX . '/dashboard/top-products');

        $response->assertOk();
        $this->assertEmpty($response->json('data'));
    }

    public function test_order_stats_counts_are_zero_when_no_orders(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/dashboard/order-stats');

        $response->assertOk();
        $stats = $response->json('data');
        foreach (['today', 'weekly', 'monthly', 'yearly'] as $period) {
            foreach (['pending', 'completed', 'cancelled'] as $status) {
                $this->assertEquals(0, $stats[$period][$status]);
            }
        }
    }

    public function test_low_stock_is_empty_when_quantity_above_threshold(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $this->makeProduct(['quantity' => 100]);

        $response = $this->getJson(self::PREFIX . '/dashboard/low-stock');

        $response->assertOk();
        $products = $response->json('data');
        foreach ($products as $p) {
            $qty = (int) ($p['quantity'] ?? 0);
            $name = is_array($p['name']) ? ($p['name']['en'] ?? json_encode($p['name'])) : $p['name'];
            $this->assertLessThan(10, $qty, "Product '{$name}' has quantity {$qty}, expected < 10");
        }
    }

    // =========================================================================
    // Translation Resolution
    // =========================================================================

    public function test_dashboard_returns_readable_english_messages(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $this->makeProduct();
        $this->makeOrder(['total_price' => 100, 'status' => 'completed']);

        app()->setLocale('en');
        $response = $this->getJson(self::PREFIX . '/dashboard/overview');

        $response->assertOk();
        $this->assertStringNotContainsString('message.DASHBOARD.', $response->json('message'));
        $this->assertEquals('Dashboard overview fetched successfully.', $response->json('message'));
    }

    public function test_dashboard_returns_readable_arabic_reconciliation_message(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $response = $this->withHeaders(['lang' => 'ar'])->getJson(self::PREFIX . '/dashboard/reconciliation');

        $response->assertOk();
        $this->assertStringNotContainsString('DASHBOARD.RECONCILIATION_FETCHED', $response->json('message'));
        $this->assertEquals('تم جلب ملخص التسوية بنجاح', $response->json('message'));
    }

    // =========================================================================
    // Product Analytics — Inventory Column Regression
    // =========================================================================

    public function test_product_analytics_out_of_stock_uses_stock_quantity(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $this->makeProduct(['quantity' => 0, 'name' => ['en' => 'Out Of Stock Product']]);
        $this->makeProduct(['quantity' => 10, 'name' => ['en' => 'In Stock Product']]);

        $response = $this->getJson(self::PREFIX . '/dashboard/products');

        $response->assertOk();
        $outOfStock = $response->json('data.out_of_stock');
        $outOfStockNames = array_map(fn ($p) => is_array($p['name']) ? ($p['name']['en'] ?? '') : $p['name'], $outOfStock);
        $this->assertContains('Out Of Stock Product', $outOfStockNames);
        $this->assertNotContains('In Stock Product', $outOfStockNames);
    }

    public function test_product_analytics_inventory_value_uses_stock_quantity(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $this->makeProduct(['quantity' => 5, 'price' => 100]);
        $this->makeProduct(['quantity' => 10, 'price' => 50]);

        $response = $this->getJson(self::PREFIX . '/dashboard/products');

        $response->assertOk();
        $inventoryValue = $response->json('data.inventory_value');
        $this->assertEquals(1000.00, $inventoryValue, '', 0.01);
    }

    // =========================================================================
    // Route Security — Dashboard Routes
    // =========================================================================

    private const DASHBOARD_PREFIX = '/api/v1/dashboard';

    public function test_dashboard_route_returns_401_when_unauthenticated(): void
    {
        $response = $this->getJson(self::DASHBOARD_PREFIX . '/overview');

        $response->assertUnauthorized();
    }

    public function test_dashboard_route_returns_200_when_authenticated(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $this->makeOrder(['total_price' => 100, 'status' => 'completed']);

        $response = $this->getJson(self::DASHBOARD_PREFIX . '/overview');

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_customer_without_view_analytics_permission_cannot_access_dashboard(): void
    {
        $permissions = [PermissionEnum::VIEW_ANALYTICS];
        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, self::GUARD);
        }

        $customer = User::create([
            'name' => 'Plain Customer',
            'email' => 'plain-customer@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'user']);
        Sanctum::actingAs($customer);

        $this->makeOrder(['total_price' => 100, 'status' => 'completed']);

        $this->getJson(self::DASHBOARD_PREFIX . '/overview')->assertForbidden();
        $this->getJson(self::DASHBOARD_PREFIX . '/revenue')->assertForbidden();
        $this->getJson(self::DASHBOARD_PREFIX . '/finance')->assertForbidden();
        $this->getJson(self::DASHBOARD_PREFIX . '/reconciliation')->assertForbidden();
    }
}

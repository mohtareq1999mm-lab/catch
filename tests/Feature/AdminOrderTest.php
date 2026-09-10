<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class AdminOrderTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';

    private User $admin;
    private User $normalUser;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'type' => 'admin',
            'is_active' => true,
            'email_verified_at' => now()]);

        $this->normalUser = User::create([
            'name' => 'Normal User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now()]);

        Role::create(['name' => 'super_admin', 'guard_name' => 'api']);
        Role::create(['name' => 'customer', 'guard_name' => 'api']);

        $this->admin->assignRole('super_admin');
        $this->normalUser->assignRole('customer');

        \Spatie\Permission\Models\Permission::create(['name' => Permission::VIEW_ORDERS, 'guard_name' => 'api']);
        \Spatie\Permission\Models\Permission::create(['name' => Permission::VIEW_ORDER, 'guard_name' => 'api']);
        \Spatie\Permission\Models\Permission::create(['name' => Permission::UPDATE_ORDER_STATUS, 'guard_name' => 'api']);

        $this->admin->givePermissionTo([
            Permission::VIEW_ORDERS,
            Permission::VIEW_ORDER,
            Permission::UPDATE_ORDER_STATUS]);

        $this->product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product-' . Str::random(6),
            'price' => 100.00,
            'product_type' => 'simple',
            'status' => 'publish']);
    }

    private function authAdmin(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function authUser(): void
    {
        Sanctum::actingAs($this->normalUser);
    }

    private function createOrder(array $overrides = []): Order
    {
        $data = array_merge([
            'user_id' => $this->normalUser->id,
            'name' => 'John Doe',
            'user_phone' => '+201234567890',
            'user_email' => 'john@example.com',
            'address' => json_encode(['city' => 'Cairo', 'street' => 'Main St']),
            'notes' => 'Leave at door',
            'price' => 100.00,
            'total_price' => 120.00,
            'shipping_price' => 20.00,
            'status' => 'pending',
            'shipping_method' => 'SCHEDULED'], $overrides);

        // Production constraint: only one pending per user (partial unique index).
        // For tests that need multiple orders, automatically use a new user for additional pendings.
        if (($data['status'] ?? 'pending') === 'pending' && isset($data['user_id'])) {
            $exists = Order::where('user_id', $data['user_id'])->where('status', 'pending')->exists();
            if ($exists) {
                $newUser = User::create([
                    'name' => 'Test User '.Str::random(4),
                    'email' => 'test-'.Str::random(6).'@example.com',
                    'password' => bcrypt('password'),
                    'type' => 'user',
                    'is_active' => true,
                    'email_verified_at' => now()]);
                $data['user_id'] = $newUser->id;
            }
        }

        return Order::create($data);
    }

    private function createOrderWithItems(Order $order): Order
    {
        $order->orderItems()->create([
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_sku' => 'TP-001',
            'product_quantity' => 2,
            'product_price' => 100.00,
            'product_total_price' => 200.00]);

        return $order->fresh();
    }

    private function createOrderWithTransaction(Order $order): Order
    {
        $order->transactions()->create([
            'uuid' => (string) Str::uuid(),
            'payment_method' => 'cod',
            'status' => 'paid',
            'amount' => 120.00,
            'user_id' => $this->normalUser->id]);

        return $order->fresh();
    }

    // =========================================================================
    // GET /orders — Index
    // =========================================================================

    public function test_index_requires_authentication()
    {
        $response = $this->getJson(self::PREFIX . '/orders');

        $response->assertStatus(401);
    }

    public function test_index_requires_view_orders_permission()
    {
        $this->authUser();
        $response = $this->getJson(self::PREFIX . '/orders');

        $response->assertStatus(403);
    }

    public function test_index_returns_paginated_orders()
    {
        $this->authAdmin();

        for ($i = 0; $i < 5; $i++) {
            $this->createOrder();
        }

        $response = $this->getJson(self::PREFIX . '/orders');

        $response->assertStatus(200);
        $response->assertJsonCount(5, 'data.data');
        $response->assertJsonStructure([
            'status', 'message', 'success',
            'data' => [
                'data',
                'links' => ['current_page', 'from', 'to', 'last_page', 'per_page', 'total']]]);
    }

    public function test_index_respects_limit_parameter()
    {
        $this->authAdmin();

        for ($i = 0; $i < 5; $i++) {
            $this->createOrder();
        }

        $response = $this->getJson(self::PREFIX . '/orders?limit=2');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data['data']);
        $this->assertEquals(2, $data['links']['per_page']);
    }

    public function test_index_enforces_max_limit()
    {
        $this->authAdmin();

        for ($i = 0; $i < 5; $i++) {
            $this->createOrder();
        }

        $response = $this->getJson(self::PREFIX . '/orders?limit=200');

        $response->assertStatus(200);
        $this->assertEquals(100, $response->json('data.links.per_page'));
    }

    public function test_index_returns_empty_data_when_no_orders()
    {
        $this->authAdmin();

        $response = $this->getJson(self::PREFIX . '/orders');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.data'));
        $this->assertEquals(0, $response->json('data.links.total'));
    }

    public function test_index_filters_by_status()
    {
        $this->authAdmin();

        $this->createOrder(['status' => 'pending']);
        $this->createOrder(['status' => 'completed']);

        $response = $this->getJson(self::PREFIX . '/orders?status=pending');

        $response->assertStatus(200);
        $orders = $response->json('data.data');
        foreach ($orders as $order) {
            $this->assertEquals('pending', $order['status']);
        }
    }

    public function test_index_filters_by_user_id()
    {
        $this->authAdmin();

        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'email_verified_at' => now()]);

        $this->createOrder(['user_id' => $this->normalUser->id]);
        $this->createOrder(['user_id' => $otherUser->id]);

        $response = $this->getJson(self::PREFIX . '/orders?user_id=' . $this->normalUser->id);

        $response->assertStatus(200);
        $orders = $response->json('data.data');
        foreach ($orders as $order) {
            $this->assertEquals($this->normalUser->id, $order['customer']['id']);
        }
    }

    public function test_index_filters_by_shipping_method()
    {
        $this->authAdmin();

        $this->createOrder(['shipping_method' => 'SCHEDULED']);
        $this->createOrder(['shipping_method' => 'FAST']);

        $response = $this->getJson(self::PREFIX . '/orders?shipping_method=FAST');

        $response->assertStatus(200);
        $orders = $response->json('data.data');
        foreach ($orders as $order) {
            $this->assertEquals('FAST', $order['shipping_method']);
        }
    }

    public function test_index_search_returns_matching_results()
    {
        $this->authAdmin();

        $matching = $this->createOrder([
            'name' => 'John Smith',
            'user_email' => 'john@example.com']);
        $nonMatching = $this->createOrder([
            'name' => 'Jane Doe',
            'user_email' => 'jane@example.com']);

        $response = $this->getJson(self::PREFIX . '/orders?search=doe');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertNotContains($matching->id, $ids);
        $this->assertContains($nonMatching->id, $ids);
    }

    public function test_index_filters_by_date_range()
    {
        $this->authAdmin();

        $this->createOrder();
        $this->createOrder();

        $response = $this->getJson(self::PREFIX . '/orders?created_from=2020-01-01&created_to=2099-12-31');

        $response->assertStatus(200);
        $this->assertGreaterThan(0, count($response->json('data.data')));
    }

    public function test_index_filters_by_product_id()
    {
        $this->authAdmin();

        $order = $this->createOrder();
        $this->createOrderWithItems($order);

        $response = $this->getJson(self::PREFIX . '/orders?product_id=' . $this->product->id);

        $response->assertStatus(200);
        $this->assertGreaterThan(0, count($response->json('data.data')));
    }

    public function test_index_does_not_include_extra_fields()
    {
        $this->authAdmin();

        $order = $this->createOrder();
        $this->createOrderWithItems($order);

        $response = $this->getJson(self::PREFIX . '/orders');

        $response->assertStatus(200);
        $orderData = $response->json('data.data.0');

        $this->assertArrayNotHasKey('customer_name', $orderData);
        $this->assertArrayNotHasKey('customer_phone', $orderData);
        $this->assertArrayNotHasKey('customer_email', $orderData);
        $this->assertArrayNotHasKey('address', $orderData);
        $this->assertArrayNotHasKey('notes', $orderData);
        $this->assertArrayNotHasKey('order_items', $orderData);
        $this->assertArrayNotHasKey('transactions', $orderData);
    }

    // =========================================================================
    // GET /orders/{id} — Show
    // =========================================================================

    public function test_show_requires_authentication()
    {
        $order = $this->createOrder();

        $response = $this->getJson(self::PREFIX . '/orders/' . $order->id);

        $response->assertStatus(401);
    }

    public function test_show_requires_view_order_permission()
    {
        $order = $this->createOrder();

        $this->authUser();
        $response = $this->getJson(self::PREFIX . '/orders/' . $order->id);

        $response->assertStatus(403);
    }

    public function test_show_returns_single_order()
    {
        $this->authAdmin();

        $order = $this->createOrder();
        $this->createOrderWithItems($order);
        $this->createOrderWithTransaction($order);

        $response = $this->getJson(self::PREFIX . '/orders/' . $order->id);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status', 'message', 'success',
            'data' => [
                'id',
                'order_number',
                'status',
                'payment_status',
                'customer',
                'customer_name',
                'customer_phone',
                'customer_email',
                'address',
                'notes',
                'price',
                'total_price',
                'order_items',
                'transactions']]);
    }

    public function test_show_returns_404_for_nonexistent_order()
    {
        $this->authAdmin();

        $response = $this->getJson(self::PREFIX . '/orders/99999');

        $response->assertStatus(404);
    }

    public function test_show_includes_customer_details_and_items()
    {
        $this->authAdmin();

        $order = $this->createOrder();
        $this->createOrderWithItems($order);
        $this->createOrderWithTransaction($order);

        $response = $this->getJson(self::PREFIX . '/orders/' . $order->id);
        $data = $response->json('data');

        $this->assertEquals('John Doe', $data['customer_name']);
        $this->assertEquals('john@example.com', $data['customer_email']);
        $this->assertEquals('+201234567890', $data['customer_phone']);
        $this->assertNotNull($data['address']);
        $this->assertEquals('Leave at door', $data['notes']);
        $this->assertCount(1, $data['order_items']);
        $this->assertCount(1, $data['transactions']);
    }

    public function test_show_returns_correct_order_number()
    {
        $this->authAdmin();

        $order = $this->createOrder();

        $response = $this->getJson(self::PREFIX . '/orders/' . $order->id);

        $response->assertStatus(200);
        $expectedOrderNumber = 'ORD-' . str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
        $this->assertEquals($expectedOrderNumber, $response->json('data.order_number'));
    }

    public function test_show_order_items_have_correct_structure()
    {
        $this->authAdmin();

        $order = $this->createOrder();
        $this->createOrderWithItems($order);

        $response = $this->getJson(self::PREFIX . '/orders/' . $order->id);
        $item = $response->json('data.order_items.0');

        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('product_id', $item);
        $this->assertArrayHasKey('product_name', $item);
        $this->assertArrayHasKey('quantity', $item);
        $this->assertArrayHasKey('unit_price', $item);
        $this->assertArrayHasKey('total_price', $item);
    }

    public function test_show_transactions_have_correct_structure()
    {
        $this->authAdmin();

        $order = $this->createOrder();
        $this->createOrderWithTransaction($order);

        $response = $this->getJson(self::PREFIX . '/orders/' . $order->id);
        $transaction = $response->json('data.transactions.0');

        $this->assertArrayHasKey('id', $transaction);
        $this->assertArrayHasKey('uuid', $transaction);
        $this->assertArrayHasKey('payment_method', $transaction);
        $this->assertArrayHasKey('status', $transaction);
        $this->assertArrayHasKey('amount', $transaction);
        $this->assertArrayHasKey('created_at', $transaction);
    }

    // =========================================================================
    // PATCH /orders/{id}/status — Update Status
    // =========================================================================

    public function test_update_status_requires_authentication()
    {
        $order = $this->createOrder();

        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', [
            'status' => 'completed']);

        $response->assertStatus(401);
    }

    public function test_update_status_requires_update_order_status_permission()
    {
        $order = $this->createOrder();

        $this->authUser();
        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', [
            'status' => 'completed']);

        $response->assertStatus(403);
    }

    public function test_update_status_updates_order_to_completed()
    {
        $this->authAdmin();

        $order = $this->createOrder();

        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', [
            'status' => 'completed']);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status', 'message', 'success',
            'data' => [
                'id',
                'order_number',
                'status',
                'payment_status',
                'customer',
                'created_at',
                'updated_at']]);
        $this->assertTrue($response->json('success'));
        $this->assertEquals('completed', $response->json('data.status'));

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'completed']);

        $fresh = $order->fresh();
        $this->assertEquals('payment-success', $fresh->payment_status);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_update_status_to_processing_is_supported()
    {
        $this->authAdmin();

        $order = $this->createOrder();

        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', [
            'status' => 'processing']);

        $response->assertStatus(200);
        $this->assertEquals('processing', $response->json('data.status'));
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'processing']);
    }

    public function test_update_status_completed_to_delivered()
    {
        $this->authAdmin();

        $order = $this->createOrder(['status' => 'completed']);

        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', [
            'status' => 'delivered']);

        $response->assertStatus(200);
        $this->assertEquals('delivered', $response->json('data.status'));
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'delivered']);
    }

    public function test_update_status_to_cancelled_sets_cancelled_at()
    {
        $this->authAdmin();

        $order = $this->createOrder();

        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', [
            'status' => 'cancelled']);

        $response->assertStatus(200);
        $this->assertEquals('cancelled', $response->json('data.status'));
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled']);
        $this->assertNotNull($order->fresh()->cancelled_at);
    }

    public function test_update_status_returns_404_for_nonexistent_order()
    {
        $this->authAdmin();

        $response = $this->patchJson(self::PREFIX . '/orders/99999/status', [
            'status' => 'completed']);

        $response->assertStatus(404);
        $this->assertFalse($response->json('success'));
    }

    public function test_update_status_returns_422_for_missing_status()
    {
        $this->authAdmin();

        $order = $this->createOrder();

        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_update_status_returns_422_for_invalid_status()
    {
        $this->authAdmin();

        $order = $this->createOrder();

        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', [
            'status' => 'shipped']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_update_status_returns_422_for_invalid_transition()
    {
        $this->authAdmin();

        $order = $this->createOrder(['status' => 'delivered']);

        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', [
            'status' => 'pending']);

        $response->assertStatus(422);
        $this->assertFalse($response->json('success'));

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'delivered']);
    }

    public function test_update_status_response_includes_order_details()
    {
        $this->authAdmin();

        $order = $this->createOrder();
        $this->createOrderWithItems($order);
        $this->createOrderWithTransaction($order);

        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', [
            'status' => 'completed']);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals('John Doe', $data['customer_name']);
        $this->assertEquals('john@example.com', $data['customer_email']);
        $this->assertCount(1, $data['order_items']);
        $this->assertCount(1, $data['transactions']);
    }

    public function test_update_status_response_includes_available_statuses()
    {
        $this->authAdmin();

        $order = $this->createOrder(['status' => 'pending']);

        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', [
            'status' => 'processing']);

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing(
            ['processing', 'completed', 'cancelled'],
            $response->json('data.available_statuses')
        );
    }

    /**
     * @dataProvider allowedTransitionsProvider
     */
    public function test_update_status_allows_every_matrix_transition(string $from, string $to)
    {
        $this->authAdmin();

        $order = $this->createOrder(['status' => $from]);

        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', [
            'status' => $to]);

        $response->assertStatus(200);
        $this->assertEquals($to, $response->json('data.status'));
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => $to]);
    }

    /**
     * @dataProvider forbiddenTransitionsProvider
     */
    public function test_update_status_rejects_every_matrix_forbidden_transition(string $from, string $to)
    {
        $this->authAdmin();

        $order = $this->createOrder(['status' => $from]);

        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', [
            'status' => $to]);

        $response->assertStatus(422);
        $this->assertFalse($response->json('success'));

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => $from]);
    }

    public static function allowedTransitionsProvider(): array
    {
        $matrix = [
            'pending' => ['pending', 'processing', 'completed', 'cancelled'],
            'processing' => ['processing', 'completed', 'cancelled'],
            'completed' => ['completed', 'delivered'],
            'delivered' => ['delivered'],
            'cancelled' => ['cancelled']];

        $cases = [];
        foreach ($matrix as $from => $targets) {
            foreach ($targets as $to) {
                $cases["$from -> $to"] = [$from, $to];
            }
        }

        return $cases;
    }

    public static function forbiddenTransitionsProvider(): array
    {
        $all = ['pending', 'processing', 'completed', 'delivered', 'cancelled'];
        $matrix = [
            'pending' => ['pending', 'processing', 'completed', 'cancelled'],
            'processing' => ['processing', 'completed', 'cancelled'],
            'completed' => ['completed', 'delivered'],
            'delivered' => ['delivered'],
            'cancelled' => ['cancelled']];

        $cases = [];
        foreach ($all as $from) {
            $forbidden = array_values(array_diff($all, $matrix[$from] ?? []));
            foreach ($forbidden as $to) {
                $cases["$from -> $to"] = [$from, $to];
            }
        }

        return $cases;
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class UserOrderDetailTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1/general';

    private User $user;
    private User $otherUser;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

        $this->user = User::create([
            'name' => 'Order Owner',
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->otherUser = User::create([
            'name' => 'Order Other',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->product = Product::create([
            'name' => 'Detail Product',
            'slug' => 'detail-product-' . Str::random(6),
            'price' => 100.00,
            'product_type' => 'simple',
            'status' => 'publish',
        ]);
    }

    private function authAs(User $user): void
    {
        Sanctum::actingAs($user);
    }

    private function createOrder(User $user, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $user->id,
            'name' => $user->name,
            'user_phone' => '+201234567890',
            'user_email' => $user->email,
            'address' => json_encode(['city' => 'Cairo', 'street' => 'Main St']),
            'notes' => 'Leave at door',
            'price' => 100.00,
            'total_price' => 120.00,
            'shipping_price' => 20.00,
            'status' => 'pending',
            'shipping_method' => 'SCHEDULED',
        ], $overrides));
    }

    private function createOrderWithItems(Order $order): Order
    {
        $order->orderItems()->create([
            'product_id' => $this->product->id,
            'product_name' => 'Detail Product',
            'product_sku' => 'DP-001',
            'product_quantity' => 2,
            'product_price' => 100.00,
            'product_total_price' => 200.00,
        ]);

        return $order->fresh();
    }

    // =========================================================================
    // GET /orders/{id} — Own order details
    // =========================================================================

    public function test_show_requires_authentication()
    {
        $order = $this->createOrder($this->user);

        $response = $this->getJson(self::PREFIX . '/orders/' . $order->id);

        $response->assertStatus(401);
    }

    public function test_show_returns_own_order_details()
    {
        $this->authAs($this->user);

        $order = $this->createOrder($this->user);
        $this->createOrderWithItems($order);

        $response = $this->getJson(self::PREFIX . '/orders/' . $order->id);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $order->id);
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.order_number', $order->order_number);
        $response->assertJsonPath('data.total', 120);
        $response->assertJsonCount(1, 'data.order_items');
    }

    public function test_show_returns_404_for_another_users_order()
    {
        $this->authAs($this->user);

        $otherOrder = $this->createOrder($this->otherUser);

        $response = $this->getJson(self::PREFIX . '/orders/' . $otherOrder->id);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_show_returns_404_for_nonexistent_order()
    {
        $this->authAs($this->user);

        $response = $this->getJson(self::PREFIX . '/orders/99999');

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_show_does_not_expose_another_users_order_by_changing_id()
    {
        $this->authAs($this->user);

        $ownOrder = $this->createOrder($this->user, ['notes' => 'SECRET-OWN']);
        $otherOrder = $this->createOrder($this->otherUser, ['notes' => 'SECRET-OTHER']);

        $ownResponse = $this->getJson(self::PREFIX . '/orders/' . $ownOrder->id);
        $ownResponse->assertStatus(200);
        $ownResponse->assertJsonPath('data.id', $ownOrder->id);

        $otherResponse = $this->getJson(self::PREFIX . '/orders/' . $otherOrder->id);
        $otherResponse->assertStatus(404);
        $this->assertNotEquals($otherOrder->id, $ownResponse->json('data.id'));
        $this->assertStringNotContainsString('SECRET-OTHER', $ownResponse->getContent());
    }

    public function test_show_does_not_accept_user_id_from_request()
    {
        $this->authAs($this->user);

        $otherOrder = $this->createOrder($this->otherUser);

        $response = $this->getJson(self::PREFIX . '/orders/' . $otherOrder->id . '?user_id=' . $this->otherUser->id);

        $response->assertStatus(404);
    }
}
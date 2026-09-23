<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * F-1 legacy-path authority: the Marvel admin PATCH /api/v1/orders/{id}/status
 * historically allowed ANY holder of update-order-status to drive an UNPAID
 * order to completed (which Baskets payment-success + commits inventory).
 * Completing an unpaid order now requires payments.mark_paid.
 */
class MarvelStatusAuthorityTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';

    private User $admin;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

        $this->admin = User::create([
            'name' => 'Status Admin',
            'email' => 'status-admin@example.com',
            'password' => bcrypt('password'),
            'type' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->customer = User::create([
            'name' => 'Status Customer',
            'email' => 'status-customer@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        Role::create(['name' => 'staff_status', 'guard_name' => 'api']);
        $this->admin->assignRole('staff_status');

        foreach ([Permission::UPDATE_ORDER_STATUS, 'payments.mark_paid'] as $name) {
            SpatiePermission::create(['name' => $name, 'guard_name' => 'api']);
        }

        $this->admin->givePermissionTo([Permission::UPDATE_ORDER_STATUS]);

        Product::create([
            'name' => 'Status Product',
            'slug' => 'status-product-' . Str::random(6),
            'price' => 100.00,
            'product_type' => 'simple',
            'status' => 1,
        ]);
    }

    private function makePendingOrder(): Order
    {
        return Order::create([
            'user_id' => $this->customer->id,
            'name' => 'Status Customer',
            'user_phone' => '+201234567890',
            'user_email' => $this->customer->email,
            'address' => json_encode(['city' => 'Cairo']),
            'price' => 100.00,
            'total_price' => 100.00,
            'status' => Order::ORDER_STATUS_PENDING,
            'payment_method' => 'online',
            'shipping_method' => 'SCHEDULED',
        ]);
    }

    /** @test */
    public function legacy_status_path_cannot_complete_unpaid_order_without_mark_paid(): void
    {
        $order = $this->makePendingOrder();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', [
            'status' => Order::ORDER_STATUS_COMPLETED,
        ]);

        $response->assertStatus(422);

        $this->assertSame(Order::ORDER_STATUS_PENDING, $order->fresh()->status);
        $this->assertNotSame(Order::PAYMENT_STATUS_SUCCESS, $order->fresh()->payment_status);
    }

    /** @test */
    public function legacy_status_path_completes_unpaid_order_with_mark_paid(): void
    {
        $order = $this->makePendingOrder();
        $this->admin->givePermissionTo(['payments.mark_paid']);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', [
            'status' => Order::ORDER_STATUS_COMPLETED,
        ]);

        $response->assertStatus(200);

        $fresh = $order->fresh();
        $this->assertSame(Order::ORDER_STATUS_COMPLETED, $fresh->status);
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $fresh->payment_status);
    }

    /** @test */
    public function legacy_status_path_completes_paid_order_without_mark_paid(): void
    {
        $order = $this->makePendingOrder();
        $order->transactions()->create([
            'user_id' => $this->customer->id,
            'payment_method' => 'myfatoorah',
            'status' => 'paid',
            'amount' => 100.00,
            'currency' => 'KWD',
            'gateway_transaction_id' => 'LEGACY-PAID-1',
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', [
            'status' => Order::ORDER_STATUS_COMPLETED,
        ]);

        $response->assertStatus(200);
        $this->assertSame(Order::ORDER_STATUS_COMPLETED, $order->fresh()->status);
    }

    /** @test */
    public function legacy_non_completed_transitions_are_unaffected(): void
    {
        $order = $this->makePendingOrder();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->patchJson(self::PREFIX . '/orders/' . $order->id . '/status', [
            'status' => Order::ORDER_STATUS_PROCESSING,
        ]);

        $response->assertStatus(200);
        $this->assertSame(Order::ORDER_STATUS_PROCESSING, $order->fresh()->status);
    }
}

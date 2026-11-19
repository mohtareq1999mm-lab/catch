<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Warehouse;
use App\Services\Order\DeliveryCompletionInvariant;
use App\Services\Order\OrderTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * ADR 0004 / issue #102: the delivery-completion rule is stated once in
 * DeliveryCompletionInvariant. These tests pin the predicate matrix and the
 * force-delivered escape hatch that deliberately bypasses it.
 */
class DeliveryCompletionInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->warehouse = Warehouse::create([
            'code' => 'WH-INV', 'name' => 'Invariant WH', 'status' => 'active', 'is_default' => true,
        ]);
    }

    private function makeOrder(array $overrides = []): Order
    {
        $user = User::factory()->create(['type' => 'customer']);

        return Order::create(array_merge([
            'user_id' => $user->id, 'name' => 'INV', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'completed',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 100, 'total_price' => 100,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ], $overrides))->refresh();
    }

    private function makeFulfillment(Order $order, string $status): Fulfillment
    {
        return Fulfillment::create([
            'order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'fulfillment_number' => 'FUL-INV-' . uniqid(),
            'status' => $status,
            'priority' => 'normal',
            'delivered_at' => $status === 'delivered' ? now() : null,
        ]);
    }

    private function invariant(): DeliveryCompletionInvariant
    {
        return app(DeliveryCompletionInvariant::class);
    }

    public function test_paid_order_with_every_fulfillment_delivered_may_complete(): void
    {
        $order = $this->makeOrder();
        $this->makeFulfillment($order, 'delivered');
        $this->makeFulfillment($order, 'delivered');

        $this->assertTrue($this->invariant()->isPaid($order));
        $this->assertTrue($this->invariant()->mayComplete($order));
    }

    public function test_unpaid_order_may_not_complete(): void
    {
        $order = $this->makeOrder(['payment_status' => Order::PAYMENT_STATUS_PENDING]);
        $this->makeFulfillment($order, 'delivered');

        $this->assertFalse($this->invariant()->isPaid($order));
        $this->assertFalse($this->invariant()->mayComplete($order));
    }

    public function test_paid_transaction_arms_the_invariant_when_the_mirror_lags(): void
    {
        $order = $this->makeOrder(['payment_status' => Order::PAYMENT_STATUS_PENDING]);
        $this->makeFulfillment($order, 'delivered');
        Transaction::create([
            'order_id' => $order->id,
            'invoice_id' => $order->id,
            'user_id' => $order->user_id,
            'uuid' => (string) Str::uuid(),
            'payment_method' => 'online',
            'status' => 'paid',
            'amount' => 100,
        ]);

        $this->assertTrue($this->invariant()->isPaid($order));
        $this->assertTrue($this->invariant()->mayComplete($order));
    }

    public function test_order_without_fulfillments_may_not_complete(): void
    {
        $order = $this->makeOrder();

        $this->assertTrue($this->invariant()->isPaid($order));
        $this->assertFalse($this->invariant()->mayComplete($order));
    }

    public function test_one_undelivered_fulfillment_blocks_completion(): void
    {
        $order = $this->makeOrder();
        $this->makeFulfillment($order, 'delivered');
        $this->makeFulfillment($order, 'ready_to_ship');

        $this->assertFalse($this->invariant()->mayComplete($order));
    }

    public function test_force_delivered_path_still_bypasses_the_invariant(): void
    {
        // Unpaid and fulfillment-free: the invariant is false on both counts.
        $order = $this->makeOrder(['payment_status' => Order::PAYMENT_STATUS_PENDING]);
        $this->assertFalse($this->invariant()->mayComplete($order));

        $admin = User::create([
            'name' => 'Invariant Admin', 'email' => 'inv-admin-' . uniqid() . '@example.com',
            'password' => bcrypt('password'), 'type' => 'admin', 'is_active' => true,
        ]);
        if (Schema::hasTable('permissions')) {
            $perm = \Spatie\Permission\Models\Permission::firstOrCreate(
                ['name' => 'update-order-status', 'guard_name' => 'api']
            );
            $admin->givePermissionTo($perm);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($admin);

        $result = app(OrderTransitionService::class)->forceDeliver($order, 'service recovery #102');

        $this->assertNotFalse($result);
        $this->assertEquals('delivered', $order->refresh()->status);
    }
}

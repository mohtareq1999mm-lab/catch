<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 6 — Fulfillment: release rule, single transition owner, idempotency,
 * ghost-state rejection, split support. Real migrations (RefreshDatabase).
 */
class FulfillmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makeWarehouse(): Warehouse
    {
        return Warehouse::create([
            'code' => 'WH-1', 'name' => 'Main', 'status' => 'active', 'is_default' => true,
        ]);
    }

    private function makeProduct(int $stock = 10): Product
    {
        return Product::create([
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-' . strtoupper(uniqid()),
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => $stock > 0, 'stock_quantity' => $stock, 'reserved_quantity' => 0,
        ]);
    }

    private function place(Product $product, Warehouse $warehouse, float $qty = 10): void
    {
        $location = Location::create([
            'warehouse_id' => $warehouse->id, 'code' => 'A-01',
            'name' => 'Bin A-01', 'type' => 'picking', 'status' => 'active', 'priority' => 10,
        ]);
        ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'warehouse_id' => $warehouse->id, 'quantity' => $qty, 'allocated_hint' => 0,
        ]);
    }

    private function makeOrder(User $user, Product $product, int $qty = 2, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $user->id, 'name' => $user->name, 'user_email' => $user->email,
            'user_phone' => '01000000001', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ], $overrides));
        $order->orderItems()->create([
            'product_id' => $product->id, 'product_name' => $product->name,
            'product_sku' => $product->sku, 'product_quantity' => $qty,
            'product_price' => 100, 'product_total_price' => 100 * $qty,
        ]);

        return $order->refresh();
    }

    public function test_release_rejects_unpaid_online_order(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        $this->makeWarehouse();
        $order = $this->makeOrder($user, $this->makeProduct());
        try {
            app(FulfillmentService::class)->releaseForOrder($order);
            $this->fail('unpaid online order must not release');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not captured', $e->getMessage());
        }
        $this->assertEquals(0, Fulfillment::count());
    }

    public function test_release_accepts_paid_online_order_with_allocation(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct(10);
        $this->place($product, $warehouse, 10);
        $order = $this->makeOrder($user, $product, 2, [
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
        ]);

        $fulfillment = app(FulfillmentService::class)->releaseForOrder($order, $warehouse->id, 'key-1');

        $this->assertEquals('pending', $fulfillment->status);
        $this->assertEquals('key-1', $fulfillment->idempotency_key);
        $this->assertCount(1, $fulfillment->items);
        $this->assertEquals(2, (int) $fulfillment->items->first()->quantity);
        // Central counters untouched by fulfillment (allocation is a plan).
        $this->assertEquals(10, (int) $product->refresh()->stock_quantity);
    }

    public function test_release_accepts_cod_with_active_reservation(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct(10);
        $this->place($product, $warehouse, 10);
        $order = $this->makeOrder($user, $product, 2, ['payment_method' => 'cod']);

        $fulfillment = app(FulfillmentService::class)->releaseForOrder($order, $warehouse->id);

        $this->assertEquals('pending', $fulfillment->status);
    }

    public function test_release_rejects_cancelled_order(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        $this->makeWarehouse();
        $order = $this->makeOrder($user, $this->makeProduct(), 2, ['status' => 'cancelled']);
        try {
            app(FulfillmentService::class)->releaseForOrder($order);
            $this->fail('cancelled order must not release');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }
    }

    public function test_release_is_idempotent_per_key_and_supports_split(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct(10);
        $this->place($product, $warehouse, 10);
        $order = $this->makeOrder($user, $product, 2, [
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
        ]);
        $service = app(FulfillmentService::class);

        $first = $service->releaseForOrder($order, $warehouse->id, 'split-key');
        $again = $service->releaseForOrder($order, $warehouse->id, 'split-key');
        $this->assertEquals($first->id, $again->id);

        // Split: second fulfillment for the same order with a different key.
        $second = $service->releaseForOrder($order, $warehouse->id, 'split-key-b');
        $this->assertNotEquals($first->id, $second->id);
        $this->assertEquals(2, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_transition_owner_enforces_dag_and_rejects_ghosts(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct(10);
        $this->place($product, $warehouse, 10);
        $order = $this->makeOrder($user, $product, 2, [
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
        ]);
        $fulfillment = app(FulfillmentService::class)->releaseForOrder($order, $warehouse->id);
        $owner = app(FulfillmentTransition::class);

        // Illegal jump rejected.
        try {
            $owner->transition($fulfillment, 'shipped');
            $this->fail('pending→shipped must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid fulfillment transition', $e->getMessage());
        }
        // Ghost states rejected.
        foreach (['packed', 'bogus'] as $ghost) {
            try {
                $owner->transition($fulfillment, $ghost);
                $this->fail("{$ghost} must throw");
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertEquals('pending', $fulfillment->refresh()->status);

        // Legal path with timestamps.
        $owner->transition($fulfillment, 'picking');
        $owner->transition($fulfillment, 'picked');
        $owner->transition($fulfillment, 'packing');
        $owner->transition($fulfillment, 'ready_to_ship');
        $fresh = $fulfillment->refresh();
        $this->assertEquals('ready_to_ship', $fresh->status);
        $this->assertNotNull($fresh->picking_started_at);
        $this->assertNotNull($fresh->ready_to_ship_at);

        // Same-state is an idempotent no-op.
        $owner->transition($fresh, 'ready_to_ship');
        $this->assertEquals('ready_to_ship', $fresh->refresh()->status);
    }
}

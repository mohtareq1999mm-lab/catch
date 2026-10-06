<?php

namespace Tests\Feature\Wms;

use App\Events\OrderCreated;
use App\Events\PaymentSucceeded;
use App\Listeners\ReleaseFulfillmentOnCodPlacement;
use App\Listeners\ReleaseFulfillmentOnPayment;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\PackingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Services\Fulfillment\FulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * P9-11: full E2E verification matrix.
 *
 * Product → Stock → Warehouse → Location → ProductLocation → Order →
 * Payment → Reservation → Fulfillment → Picking → Batch → Packing →
 * Package → Ready-to-ship → Shipment → Delivery → Order Flow completion,
 * driven through the real HTTP surface wherever it exists (warehouse,
 * picking, batch, packing, packages, order-cancel, shipments) and through
 * the real event listeners for triggers. Catalog/order creation stay
 * service-level (out of WMS HTTP scope).
 * Sequential only (shared MySQL).
 */
class WmsEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-warehouse', 'manage-warehouse', 'view-location', 'manage-location',
            'view-fulfillment', 'manage-fulfillment', 'fulfillment.create', 'fulfillment.cancel',
            'picking-execute', 'packing-execute', 'packing.complete',
            'batch.manage', 'batch.operate', 'order.cancel-during-fulfillment',
            'view-shipment', 'create-shipment', 'update-shipment',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->product = Product::create([
            'name' => 'PE2E', 'slug' => 'pe2e-' . uniqid(), 'sku' => 'SKU-E2E-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 100, 'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);
    }

    private function user(?int $home, array $permissions): User
    {
        $user = User::factory()->create(['type' => 'staff', 'warehouse_id' => $home]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function operator(int $warehouseId): User
    {
        return $this->user($warehouseId, [
            'view-warehouse', 'manage-warehouse', 'view-location', 'manage-location',
            'view-fulfillment', 'manage-fulfillment', 'fulfillment.create', 'fulfillment.cancel',
            'picking-execute', 'packing-execute', 'packing.complete',
            'batch.manage', 'batch.operate', 'order.cancel-during-fulfillment',
            'view-shipment', 'create-shipment', 'update-shipment',
        ]);
    }

    private function makePaidOnlineOrder(): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'OE', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'PE2E',
            'product_sku' => 'SKU-E2E-1', 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);
        app(\App\Services\OrderFlow\OrderFlowService::class)->assignFlowToOrder($order->refresh(), 'local');

        return $order->refresh();
    }

    // ---------- happy path ----------

    public function test_e2e_paid_order_to_delivered_completion(): void
    {
        $operator = $this->user(null, [
            'manage-warehouse', 'manage-location', 'view-fulfillment', 'manage-fulfillment',
            'fulfillment.create', 'picking-execute', 'packing-execute', 'packing.complete',
            'batch.manage', 'batch.operate', 'view-shipment', 'create-shipment', 'update-shipment',
        ]);
        $auth = fn () => $this->actingAs($operator, 'sanctum');

        // Warehouse + location through the real admin surface.
        $warehouseId = $auth()->postJson('/api/v1/admin/warehouses', [
            'code' => 'WH-E2E', 'name' => 'E2E Warehouse', 'is_default' => true,
        ])->assertStatus(201)->json('data.id');
        // Global onboarding: the site is created homeless, then the
        // operator is homed into it before scoped writes (a homeless
        // manage-location holder is correctly refused below the surface).
        $operator->update(['warehouse_id' => $warehouseId]);
        $locationId = $auth()->postJson('/api/v1/admin/locations', [
            'warehouse_id' => $warehouseId, 'code' => 'E2E-01',
            'barcode' => 'E2E-LOC-01', 'name' => 'Bin', 'type' => 'picking',
        ])->assertStatus(201)->json('data.id');
        $this->location = Location::find($locationId);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $locationId,
            'warehouse_id' => $warehouseId, 'quantity' => 100, 'allocated_hint' => 0,
        ]);
        $stationId = PackingStation::create([
            'warehouse_id' => $warehouseId, 'code' => 'E2E-ST',
            'name' => 'E2E Station', 'status' => 'active',
        ])->id;

        // Payment → auto-release (real listener, deterministic key).
        $order = $this->makePaidOnlineOrder();
        app(ReleaseFulfillmentOnPayment::class)->handle(new PaymentSucceeded($order));
        $fulfillment = Fulfillment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame($warehouseId, (int) $fulfillment->warehouse_id);
        $fulfillmentId = $fulfillment->id;

        // Batch: create → assign → start.
        $batchId = $auth()->postJson('/api/v1/admin/batches', [
            'fulfillment_ids' => [$fulfillmentId],
        ])->assertStatus(201)->json('data.id');
        $auth()->postJson("/api/v1/admin/batches/{$batchId}/assign", ['user_id' => $operator->id])->assertStatus(200);
        $auth()->postJson("/api/v1/admin/batches/{$batchId}/start", [])->assertStatus(200);

        // Pick every task through the scan flow, then refresh to completion.
        $tasks = \App\Models\Fulfillment\PickingTask::where('batch_id', $batchId)->orderBy('id')->get();
        $this->assertNotEmpty($tasks);
        foreach ($tasks as $task) {
            $auth()->postJson("/api/v1/admin/picking-tasks/{$task->id}/claim", [])->assertStatus(200);
            $auth()->postJson("/api/v1/admin/picking-tasks/{$task->id}/confirm", [
                'location' => 'E2E-LOC-01', 'product' => 'SKU-E2E-1',
                'quantity' => 2, 'op_seq' => 1,
            ])->assertStatus(200);
        }
        $auth()->postJson("/api/v1/admin/batches/{$batchId}/refresh-progress", [])
            ->assertStatus(200)->assertJsonPath('data.status', 'completed');
        $this->assertSame('picked', $fulfillment->fresh()->status);

        // Pack: task → assign → start → package → pack → verify.
        $packingTaskId = $auth()->postJson(
            "/api/v1/admin/fulfillments/{$fulfillmentId}/create-packing-task", []
        )->assertStatus(201)->json('data.id');
        $auth()->postJson("/api/v1/admin/packing-tasks/{$packingTaskId}/assign", ['station_id' => $stationId])->assertStatus(200);
        $auth()->postJson("/api/v1/admin/packing-tasks/{$packingTaskId}/start", [])->assertStatus(200);
        $packageId = $auth()->postJson('/api/v1/admin/packages', ['fulfillment_id' => $fulfillmentId])->assertStatus(201)->json('data.id');
        $itemId = $fulfillment->items()->firstOrFail()->id;
        $auth()->postJson("/api/v1/admin/packages/{$packageId}/add-item", [
            'fulfillment_item_id' => $itemId, 'quantity' => 2,
        ])->assertStatus(200);
        $auth()->postJson("/api/v1/admin/packages/{$packageId}/seal", [])->assertStatus(200);
        $auth()->postJson("/api/v1/admin/packing-tasks/{$packingTaskId}/pack", [
            'weight' => 2.5, 'dimensions' => ['length' => 30, 'width' => 20, 'height' => 10],
        ])->assertStatus(200);
        $auth()->postJson("/api/v1/admin/packing-tasks/{$packingTaskId}/verify", [])->assertStatus(200);
        $this->assertSame('ready_to_ship', $fulfillment->fresh()->status);

        // Ship: label → dispatch → deliver.
        $shipmentId = $auth()->postJson(
            "/api/v1/admin/fulfillments/{$fulfillmentId}/shipments", ['courier' => 'E2E-Post']
        )->assertStatus(201)->json('data.id');
        $auth()->postJson("/api/v1/admin/shipments/{$shipmentId}/dispatch", [])->assertStatus(200);
        $this->assertSame('shipped', $fulfillment->fresh()->status);
        $auth()->postJson("/api/v1/admin/shipments/{$shipmentId}/deliver", [])->assertStatus(200);
        $this->assertSame('delivered', $fulfillment->fresh()->status);
        $this->assertSame('delivered', Shipment::find($shipmentId)->status);

        // Order Flow completion: paid + completed + all delivered → delivered.
        app(\App\Services\General\OrderService::class)
            ->changeOrderStatus(null, 'completed', $order->id);
        $this->assertSame('completed', $order->fresh()->status);
        $auth()->postJson("/api/v1/admin/shipments/{$shipmentId}/deliver", [])->assertStatus(200);
        $this->assertSame('delivered', $order->fresh()->status);

        // Stock integrity end-to-end: committed goods left stock once at
        // reservation and were never double-moved by warehouse commands.
        $this->assertEquals(100, (float) $this->product->fresh()->stock_quantity);
    }

    // ---------- failure paths ----------

    public function test_e2e_unpaid_online_order_never_releases(): void
    {
        Warehouse::create(['code' => 'WH-EF', 'name' => 'F', 'status' => 'active', 'is_default' => true]);
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'OU', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'payment_method' => 'online',
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_NONE,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'PE2E',
            'product_sku' => 'SKU-E2E-1', 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);

        try {
            app(ReleaseFulfillmentOnPayment::class)->handle(new PaymentSucceeded($order));
            $this->fail('Expected release refusal for uncaptured order');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not captured', $e->getMessage());
        }
        $this->assertSame(0, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_e2e_unreserved_cod_order_never_releases(): void
    {
        Warehouse::create(['code' => 'WH-EG', 'name' => 'G', 'status' => 'active', 'is_default' => true]);
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'OC', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'payment_method' => 'cod',
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_NONE,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery',
            'address' => ['city' => 'Cairo'],
        ]);

        // Graceful skip (no throw): reservation not ACTIVE.
        app(ReleaseFulfillmentOnCodPlacement::class)->handle(new OrderCreated($order));
        $this->assertSame(0, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_e2e_cancel_during_picking_halts_chain(): void
    {
        $warehouse = Warehouse::create(['code' => 'WH-EH', 'name' => 'H', 'status' => 'active', 'is_default' => true]);
        $location = Location::create([
            'warehouse_id' => $warehouse->id, 'code' => 'EH-01', 'barcode' => 'EH-LOC-01',
            'name' => 'Bin', 'type' => 'picking', 'status' => 'active', 'priority' => 1,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $location->id,
            'warehouse_id' => $warehouse->id, 'quantity' => 100, 'allocated_hint' => 0,
        ]);
        $operator = $this->operator($warehouse->id);
        $auth = fn () => $this->actingAs($operator, 'sanctum');

        $order = $this->makePaidOnlineOrder();
        app(ReleaseFulfillmentOnPayment::class)->handle(new PaymentSucceeded($order));
        $fulfillment = Fulfillment::where('order_id', $order->id)->firstOrFail();

        $taskId = app(\App\Services\Fulfillment\OrderPickingService::class)
            ->createTasksForFulfillment($fulfillment)[0]->id;

        // Cancel mid-picking through the order surface.
        $auth()->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason' => 'fraud hold'])
            ->assertStatus(200);
        $this->assertSame('cancelled', $fulfillment->fresh()->status);

        // The chain is dead: picking commands refuse, batch creation refuses.
        $auth()->postJson("/api/v1/admin/picking-tasks/{$taskId}/claim", [])->assertStatus(422);
        $auth()->postJson('/api/v1/admin/batches', ['fulfillment_ids' => [$fulfillment->id]])->assertStatus(422);
        $auth()->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/shipments", []
        )->assertStatus(422);
        $this->assertSame(0, Shipment::where('fulfillment_id', $fulfillment->id)->count());
    }

    public function test_e2e_replay_converges_and_foreign_probes_404(): void
    {
        $warehouse = Warehouse::create(['code' => 'WH-EI', 'name' => 'I', 'status' => 'active', 'is_default' => true]);
        $other = Warehouse::create(['code' => 'WH-EJ', 'name' => 'J', 'status' => 'active', 'is_default' => false]);
        $operator = $this->operator($warehouse->id);

        $order = $this->makePaidOnlineOrder();
        $first = app(FulfillmentService::class)->releaseForOrder($order, $warehouse->id, 'e2e-replay-1');
        $replay = app(FulfillmentService::class)->releaseForOrder($order->refresh(), $warehouse->id, 'e2e-replay-1');
        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, Fulfillment::where('order_id', $order->id)->count());

        // Foreign-homed operator probing fulfillment + shipment rows → 404.
        $foreign = $this->operator($other->id);
        $this->actingAs($foreign, 'sanctum')
            ->getJson("/api/v1/admin/fulfillments/{$first->id}")->assertStatus(404);
        $this->actingAs($foreign, 'sanctum')
            ->getJson("/api/v1/admin/batches/pending-fulfillments?warehouse_id={$warehouse->id}")
            ->assertStatus(404);
    }
}

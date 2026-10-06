<?php

namespace Tests\Feature\Wms;

use App\Events\OrderCreated;
use App\Events\PaymentSucceeded;
use App\Listeners\ReleaseFulfillmentOnCodPlacement;
use App\Listeners\ReleaseFulfillmentOnPayment;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * P11 — E2E completion matrix (extends the P9-11 WmsEndToEndTest baseline).
 *
 * Covers the §14 scenarios missing from the baseline over the real HTTP
 * surface: COD positive full chain, missing-placement controlled failure,
 * cancel-before-picking. Catalog/order seeding stays service-level (out of
 * WMS HTTP scope); everything else goes through HTTP or the real listeners.
 * Sequential only (shared MySQL).
 */
class WmsE2ECompletionTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

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
            'name' => 'PE2C', 'slug' => 'pe2c-' . uniqid(), 'sku' => 'SKU-E2E-COD',
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

    private function operator(?int $home = null): User
    {
        return $this->user($home, [
            'manage-warehouse', 'manage-location', 'view-fulfillment', 'manage-fulfillment',
            'fulfillment.create', 'fulfillment.cancel', 'picking-execute', 'packing-execute',
            'packing.complete', 'batch.manage', 'batch.operate', 'order.cancel-during-fulfillment',
            'view-shipment', 'create-shipment', 'update-shipment',
        ]);
    }

    private function onboardSite(User $operator, string $prefix): array
    {
        $auth = fn () => $this->actingAs($operator, 'sanctum');

        $warehouseId = $auth()->postJson('/api/v1/admin/warehouses', [
            'code' => 'WH-' . $prefix, 'name' => $prefix . ' Warehouse', 'is_default' => true,
        ])->assertStatus(201)->json('data.id');
        $operator->update(['warehouse_id' => $warehouseId]);
        $locationId = $auth()->postJson('/api/v1/admin/locations', [
            'warehouse_id' => $warehouseId, 'code' => $prefix . '-01',
            'barcode' => $prefix . '-LOC-01', 'name' => 'Bin', 'type' => 'picking',
        ])->assertStatus(201)->json('data.id');

        return [$auth, $warehouseId, $locationId];
    }

    private function makeOrder(array $overrides): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create(array_merge([
            'user_id' => $user->id, 'name' => 'OE', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'pending',
            'fulfillment_status' => 'pending',
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery',
            'address' => ['city' => 'Cairo'],
        ], $overrides));
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'PE2C',
            'product_sku' => 'SKU-E2E-COD', 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);
        app(\App\Services\OrderFlow\OrderFlowService::class)->assignFlowToOrder($order->refresh(), 'local');

        return $order->refresh();
    }

    public function test_e2e_cod_order_full_chain_to_delivered_fulfillment(): void
    {
        $operator = $this->operator();
        [$auth, $warehouseId, $locationId] = $this->onboardSite($operator, 'COD');
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $locationId,
            'warehouse_id' => $warehouseId, 'quantity' => 100, 'allocated_hint' => 0,
        ]);
        $stationId = PackingStation::create([
            'warehouse_id' => $warehouseId, 'code' => 'COD-ST',
            'name' => 'COD Station', 'status' => 'active',
        ])->id;

        // COD placement → auto-release (real listener, ACTIVE reservation).
        $order = $this->makeOrder([
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'payment_method' => 'cod',
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
        ]);
        app(ReleaseFulfillmentOnCodPlacement::class)->handle(new OrderCreated($order));
        $fulfillment = Fulfillment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame($warehouseId, (int) $fulfillment->warehouse_id);
        $fulfillmentId = $fulfillment->id;

        // Pick through the batch surface.
        $batchId = $auth()->postJson('/api/v1/admin/batches', [
            'fulfillment_ids' => [$fulfillmentId],
        ])->assertStatus(201)->json('data.id');
        $auth()->postJson("/api/v1/admin/batches/{$batchId}/assign", ['user_id' => $operator->id])->assertStatus(200);
        $auth()->postJson("/api/v1/admin/batches/{$batchId}/start", [])->assertStatus(200);
        $tasks = \App\Models\Fulfillment\PickingTask::where('batch_id', $batchId)->orderBy('id')->get();
        $this->assertNotEmpty($tasks);
        foreach ($tasks as $task) {
            $auth()->postJson("/api/v1/admin/picking-tasks/{$task->id}/claim", [])->assertStatus(200);
            $auth()->postJson("/api/v1/admin/picking-tasks/{$task->id}/confirm", [
                'location' => 'COD-LOC-01', 'product' => 'SKU-E2E-COD',
                'quantity' => 2, 'op_seq' => 1,
            ])->assertStatus(200);
        }
        $auth()->postJson("/api/v1/admin/batches/{$batchId}/refresh-progress", [])
            ->assertStatus(200)->assertJsonPath('data.status', 'completed');
        $this->assertSame('picked', $fulfillment->fresh()->status);

        // Pack → ready to ship.
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

        // Ship → deliver. Fulfillment + shipment converge; the ORDER stays
        // pending because COD payment is still outstanding — completing an
        // unpaid order is a finance act (payments.mark_paid, F-1) outside
        // WMS scope, and the completion invariant correctly refuses it.
        $shipmentId = $auth()->postJson(
            "/api/v1/admin/fulfillments/{$fulfillmentId}/shipments", ['courier' => 'COD-Post']
        )->assertStatus(201)->json('data.id');
        $auth()->postJson("/api/v1/admin/shipments/{$shipmentId}/dispatch", [])->assertStatus(200);
        $auth()->postJson("/api/v1/admin/shipments/{$shipmentId}/deliver", [])->assertStatus(200);
        $this->assertSame('delivered', $fulfillment->fresh()->status);
        $this->assertSame('delivered', Shipment::find($shipmentId)->status);
        $this->assertSame('pending', $order->fresh()->status);

        $this->assertEquals(100, (float) $this->product->fresh()->stock_quantity);
    }

    public function test_e2e_missing_placement_releases_flagged_without_pickable_tasks(): void
    {
        $operator = $this->operator();
        [$auth, $warehouseId] = $this->onboardSite($operator, 'NOPL');
        // Deliberately NO ProductLocation row.

        $order = $this->makeOrder([
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
        ]);
        app(ReleaseFulfillmentOnPayment::class)->handle(new PaymentSucceeded($order));
        $fulfillment = Fulfillment::where('order_id', $order->id)->firstOrFail();

        // Controlled failure, not silent success: the item is flagged with no
        // location allocation and zero pickable tasks exist.
        $item = $fulfillment->items()->firstOrFail();
        $this->assertNull($item->product_location_id);
        $this->assertNotEmpty($item->notes);
        $this->assertSame(
            0,
            \App\Models\Fulfillment\PickingTask::whereIn(
                'fulfillment_item_id', $fulfillment->items()->pluck('id')
            )->count()
        );

        // The batch surface refuses a zero-pickable-item fulfillment loudly.
        $auth()->postJson('/api/v1/admin/batches', [
            'fulfillment_ids' => [$fulfillment->id],
        ])->assertStatus(422);
        $this->assertSame('pending', $fulfillment->fresh()->status);
    }

    public function test_e2e_cancel_before_picking_restores_reservation_and_kills_chain(): void
    {
        $operator = $this->operator();
        [$auth, $warehouseId, $locationId] = $this->onboardSite($operator, 'CBP');
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $locationId,
            'warehouse_id' => $warehouseId, 'quantity' => 100, 'allocated_hint' => 0,
        ]);

        $order = $this->makeOrder([
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
        ]);
        app(ReleaseFulfillmentOnPayment::class)->handle(new PaymentSucceeded($order));
        $fulfillment = Fulfillment::where('order_id', $order->id)->firstOrFail();

        // Cancel before a single pick through the order surface.
        $stockBefore = (float) $this->product->fresh()->stock_quantity;
        $auth()->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason' => 'customer request'])
            ->assertStatus(200);
        $this->assertSame('cancelled', $fulfillment->fresh()->status);
        $this->assertSame(Order::INVENTORY_STATE_RESTORED, $order->fresh()->inventory_state);

        // Nothing downstream can start: no batch, no shipment. The committed
        // lines are restored exactly once (fixture seeds COMMITTED directly
        // without the checkout-time decrement, so assert the +2 delta, not
        // an absolute level).
        $auth()->postJson('/api/v1/admin/batches', ['fulfillment_ids' => [$fulfillment->id]])->assertStatus(422);
        $auth()->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/shipments", []
        )->assertStatus(422);
        $this->assertSame(0, Shipment::where('fulfillment_id', $fulfillment->id)->count());
        $this->assertEquals($stockBefore + 2, (float) $this->product->fresh()->stock_quantity);
    }
}

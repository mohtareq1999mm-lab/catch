<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\Package;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\PackingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WmsBulkOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private User $admin;
    private PackingStation $station;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-warehouse', 'view-location', 'view-fulfillment', 'manage-fulfillment',
            'picking-execute', 'packing-execute', 'packing.complete',
            'view-shipment', 'create-shipment', 'update-shipment',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create([
            'code' => 'WH-BULK',
            'name' => 'Bulk Warehouse',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->station = PackingStation::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'ST-BULK-1',
            'name' => 'Bulk Station 1',
            'status' => 'active',
        ]);

        $this->admin = User::factory()->create([
            'type' => 'admin',
            'warehouse_id' => $this->warehouse->id,
        ]);

        foreach ([
            'view-fulfillment', 'packing-execute', 'packing.complete',
            'view-shipment', 'create-shipment', 'update-shipment',
        ] as $perm) {
            $this->admin->givePermissionTo($perm);
        }
    }

    private function makeFulfillment(string $status = 'pending'): Fulfillment
    {
        static $seq = 1;
        $customer = User::factory()->create(['type' => 'customer']);

        $order = Order::create([
            'user_id' => $customer->id,
            'name' => 'Customer ' . $seq,
            'user_email' => 'cust' . $seq . '@test.com',
            'user_phone' => '01000' . $seq,
            'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 100,
            'total_price' => 100,
            'currency_code' => 'SAR',
            'base_currency_code' => 'SAR',
            'fulfillment_type' => 'delivery',
            'address' => ['city' => 'Riyadh'],
        ]);

        $product = Product::create([
            'name' => 'Bulk Prod ' . $seq,
            'slug' => 'bulk-prod-' . $seq . '-' . uniqid(),
            'sku' => 'SKU-BLK-' . $seq,
            'price' => 100,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 50,
        ]);

        $orderItem = $order->orderItems()->create([
            'product_id' => $product->id,
            'order_quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
        ]);

        $fulfillment = Fulfillment::create([
            'order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'fulfillment_number' => 'FUL-BLK-' . str_pad((string)$seq++, 6, '0', STR_PAD_LEFT),
            'status' => $status,
            'priority' => 'normal',
        ]);

        $fulfillment->items()->create([
            'order_item_id' => $orderItem->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'quantity_picked' => in_array($status, ['picked', 'packing', 'ready_to_ship', 'shipped'], true) ? 1 : 0,
            'status' => in_array($status, ['picked', 'packing', 'ready_to_ship', 'shipped'], true) ? 'picked' : 'pending',
        ]);

        return $fulfillment->fresh();
    }

    public function test_bulk_create_packing_tasks(): void
    {
        $f1 = $this->makeFulfillment('picked');
        $f2 = $this->makeFulfillment('picked');
        $f3 = $this->makeFulfillment('pending'); // Ineligible: not picked

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/fulfillments/create-packing-tasks', [
                'fulfillment_ids' => [$f1->id, $f2->id, $f3->id],
                'packing_station_id' => $this->station->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total', 3)
            ->assertJsonPath('data.summary.success_count', 2)
            ->assertJsonPath('data.summary.failed_count', 1);

        $this->assertEquals('packing', $f1->fresh()->status);
        $this->assertEquals('packing', $f2->fresh()->status);
        $this->assertEquals('pending', $f3->fresh()->status);

        $this->assertDatabaseHas('packing_tasks', [
            'fulfillment_id' => $f1->id,
            'packing_station_id' => $this->station->id,
        ]);
        $this->assertDatabaseHas('packing_tasks', [
            'fulfillment_id' => $f2->id,
            'packing_station_id' => $this->station->id,
        ]);
    }

    public function test_bulk_create_shipments(): void
    {
        $f1 = $this->makeFulfillment('ready_to_ship');
        $f2 = $this->makeFulfillment('ready_to_ship');
        $f3 = $this->makeFulfillment('picking'); // Ineligible: not ready_to_ship

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/fulfillments/create-shipments', [
                'fulfillment_ids' => [$f1->id, $f2->id, $f3->id],
                'courier' => 'Aramex',
                'shipping_method' => 'express',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total', 3)
            ->assertJsonPath('data.summary.success_count', 2)
            ->assertJsonPath('data.summary.failed_count', 1);

        $this->assertDatabaseHas('shipments', [
            'fulfillment_id' => $f1->id,
            'courier' => 'Aramex',
        ]);
        $this->assertDatabaseHas('shipments', [
            'fulfillment_id' => $f2->id,
            'courier' => 'Aramex',
        ]);
    }

    public function test_bulk_dispatch_and_deliver(): void
    {
        $f1 = $this->makeFulfillment('ready_to_ship');
        $f2 = $this->makeFulfillment('ready_to_ship');

        $shipmentService = app(\App\Services\Shipment\ShipmentService::class);
        $s1 = $shipmentService->createForFulfillment($f1, ['courier' => 'SMSA']);
        $s2 = $shipmentService->createForFulfillment($f2, ['courier' => 'SMSA']);

        // 1. Bulk Dispatch
        $dispatchRes = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/shipments/bulk-dispatch', [
                'shipment_ids' => [$s1->id, $s2->id],
                'notes' => 'Handed over to SMSA truck',
            ]);

        $dispatchRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.success_count', 2);

        $this->assertEquals('picked_up', $s1->fresh()->status);
        $this->assertEquals('picked_up', $s2->fresh()->status);
        $this->assertEquals('shipped', $f1->fresh()->status);
        $this->assertEquals('shipped', $f2->fresh()->status);

        // 2. Bulk Deliver
        $deliverRes = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/shipments/bulk-deliver', [
                'shipment_ids' => [$s1->id, $s2->id],
                'notes' => 'Delivered to customers',
            ]);

        $deliverRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.success_count', 2);

        $this->assertEquals('delivered', $s1->fresh()->status);
        $this->assertEquals('delivered', $s2->fresh()->status);
        $this->assertEquals('delivered', $f1->fresh()->status);
        $this->assertEquals('delivered', $f2->fresh()->status);
    }
}

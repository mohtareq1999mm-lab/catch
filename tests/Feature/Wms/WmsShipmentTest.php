<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Inventory\OrderReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * P9-8: fulfillment-scoped shipment HTTP adapter.
 *
 * State ownership: ShipmentService owns shipment rows, FulfillmentTransition
 * owns fulfillment moves (inside the service), Order Flow owns orders. Scope
 * resolves via shipment.fulfillment_id → fulfillment.warehouse_id;
 * order-only labels (null fulfillment) 404 on this surface.
 * Sequential only (shared MySQL).
 */
class WmsShipmentTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-shipment', 'create-shipment', 'update-shipment', 'manage-warehouse',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouseA = Warehouse::create([
            'code' => 'WH-SA', 'name' => 'Ship A', 'status' => 'active', 'is_default' => true,
        ]);
        $this->warehouseB = Warehouse::create([
            'code' => 'WH-SB', 'name' => 'Ship B', 'status' => 'active', 'is_default' => false,
        ]);

        $location = Location::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'SA-01',
            'barcode' => 'SA-LOC-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $product = Product::create([
            'name' => 'PS', 'slug' => 'ps-' . uniqid(), 'sku' => 'SKU-SHIP-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'warehouse_id' => $this->warehouseA->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);
    }

    private function user(?int $home, array $permissions = []): User
    {
        $user = User::factory()->create(['type' => 'staff', 'warehouse_id' => $home]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function dispatcher(?int $home = null): User
    {
        return $this->user($home ?? $this->warehouseA->id, [
            'view-shipment', 'create-shipment', 'update-shipment',
        ]);
    }

    private function makeOrder(): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'OS', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'price' => 1000, 'total_price' => 1000,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => Product::where('sku', 'SKU-SHIP-1')->firstOrFail()->id,
            'product_name' => 'PS', 'product_sku' => 'SKU-SHIP-1',
            'product_quantity' => 10, 'product_price' => 100, 'product_total_price' => 1000,
        ]);

        return $order->refresh();
    }

    private function readyFulfillment(?string $key = null): Fulfillment
    {
        $order = $this->makeOrder();
        app(OrderReservationService::class)->reserveForOrder($order);
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order->refresh(), $this->warehouseA->id, $key ?? ('sh-' . uniqid()));
        $t = app(FulfillmentTransition::class);
        foreach (['picking', 'picked', 'packing', 'ready_to_ship'] as $state) {
            $t->transition($fulfillment, $state);
            $fulfillment = $fulfillment->refresh();
        }

        return $fulfillment;
    }

    private function createHttp(User $actor, int $fulfillmentId, array $payload = [])
    {
        return $this->actingAs($actor, 'sanctum')
            ->postJson("/api/v1/admin/fulfillments/{$fulfillmentId}/shipments", $payload);
    }

    // ---------- auth / permission ----------

    public function test_unauthenticated_shipment_endpoints_return_401(): void
    {
        $this->getJson('/api/v1/admin/shipments?fulfillment_id=1')->assertStatus(401);
        $this->getJson('/api/v1/admin/shipments/1')->assertStatus(401);
        $this->postJson('/api/v1/admin/fulfillments/1/shipments', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/shipments/1/dispatch', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/shipments/1/deliver', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/shipments/1/cancel', [])->assertStatus(401);
    }

    public function test_shipment_commands_require_granular_permissions(): void
    {
        $fulfillment = $this->readyFulfillment();
        $viewer = $this->user($this->warehouseA->id, ['view-shipment']);

        $this->createHttp($viewer, $fulfillment->id)->assertStatus(403);

        $dispatcher = $this->dispatcher();
        $shipmentId = $this->createHttp($dispatcher, $fulfillment->id, ['courier' => 'Aramex'])
            ->assertStatus(201)->json('data.id');

        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/v1/admin/shipments/{$shipmentId}/dispatch", [])->assertStatus(403);
        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/v1/admin/shipments/{$shipmentId}/deliver", [])->assertStatus(403);
        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/v1/admin/shipments/{$shipmentId}/cancel", ['reason' => 'x'])->assertStatus(403);
        $this->assertSame('label_created', Shipment::find($shipmentId)->status);
    }

    // ---------- reads + scope ----------

    public function test_shipment_reads_scope_to_home_warehouse(): void
    {
        $fulfillment = $this->readyFulfillment();
        $dispatcher = $this->dispatcher();
        $shipmentId = $this->createHttp($dispatcher, $fulfillment->id)
            ->assertStatus(201)->json('data.id');

        $list = $this->actingAs($dispatcher, 'sanctum')
            ->getJson("/api/v1/admin/shipments?fulfillment_id={$fulfillment->id}");
        $list->assertStatus(200);
        $this->assertNotEmpty($list->json('data'));
        $this->actingAs($dispatcher, 'sanctum')
            ->getJson("/api/v1/admin/shipments/{$shipmentId}")->assertStatus(200);

        // fulfillment_id required; foreign probes 404, never rows.
        $this->actingAs($dispatcher, 'sanctum')->getJson('/api/v1/admin/shipments')->assertStatus(422);
        $foreign = $this->dispatcher($this->warehouseB->id);
        $this->actingAs($foreign, 'sanctum')
            ->getJson("/api/v1/admin/shipments?fulfillment_id={$fulfillment->id}")->assertStatus(404);
        $this->actingAs($foreign, 'sanctum')
            ->getJson("/api/v1/admin/shipments/{$shipmentId}")->assertStatus(404);

        // Order-only label (null fulfillment) is outside the WMS surface.
        $legacy = Shipment::create([
            'order_id' => $fulfillment->order_id, 'fulfillment_id' => null,
            'status' => 'label_created',
        ]);
        $this->actingAs($dispatcher, 'sanctum')
            ->getJson("/api/v1/admin/shipments/{$legacy->id}")->assertStatus(404);
    }

    // ---------- create ----------

    public function test_create_shipment_guards_state_and_single_active_label(): void
    {
        $dispatcher = $this->dispatcher();
        $fulfillment = $this->readyFulfillment('c1');

        $response = $this->createHttp($dispatcher, $fulfillment->id, [
            'courier' => 'Aramex', 'shipping_method' => 'standard',
            'idempotency_key' => 'key-c1',
        ]);
        $response->assertStatus(201);
        $this->assertSame('label_created', $response->json('data.status'));
        $this->assertSame('Aramex', $response->json('data.courier'));

        // Unkeyed duplicate → 409, no second row.
        $this->createHttp($dispatcher, $fulfillment->id)->assertStatus(409);
        $this->assertSame(1, Shipment::where('fulfillment_id', $fulfillment->id)->count());

        // Keyed replay → 200 same row.
        $replay = $this->createHttp($dispatcher, $fulfillment->id, ['idempotency_key' => 'key-c1']);
        $replay->assertStatus(200);
        $this->assertSame($response->json('data.id'), $replay->json('data.id'));

        // Same key for a DIFFERENT fulfillment → 409, never its row.
        $other = $this->readyFulfillment('c2');
        $this->createHttp($dispatcher, $other->id, ['idempotency_key' => 'key-c1'])->assertStatus(409);
        $this->assertSame(0, Shipment::where('fulfillment_id', $other->id)->count());

        // Non-ready fulfillment → 422.
        $order = $this->makeOrder();
        app(OrderReservationService::class)->reserveForOrder($order);
        $early = app(FulfillmentService::class)
            ->releaseForOrder($order->refresh(), $this->warehouseA->id, 'early-1');
        $this->createHttp($dispatcher, $early->id)->assertStatus(422);

        // Cross-warehouse create → 403.
        $foreign = $this->dispatcher($this->warehouseB->id);
        $this->createHttp($foreign, $other->id)->assertStatus(403);
    }

    // ---------- dispatch / deliver ----------

    public function test_dispatch_moves_shipment_and_fulfillment_once(): void
    {
        $dispatcher = $this->dispatcher();
        $fulfillment = $this->readyFulfillment('d1');
        $shipmentId = $this->createHttp($dispatcher, $fulfillment->id)
            ->assertStatus(201)->json('data.id');

        $dispatch = $this->actingAs($dispatcher, 'sanctum')
            ->postJson("/api/v1/admin/shipments/{$shipmentId}/dispatch", ['notes' => 'handed to courier']);
        $dispatch->assertStatus(200);
        $this->assertSame('picked_up', Shipment::find($shipmentId)->status);
        $this->assertSame('shipped', $fulfillment->fresh()->status);

        // Duplicate dispatch refuses loudly — never a silent no-op.
        $this->actingAs($dispatcher, 'sanctum')
            ->postJson("/api/v1/admin/shipments/{$shipmentId}/dispatch", [])->assertStatus(422);
        $this->assertSame('picked_up', Shipment::find($shipmentId)->status);
    }

    public function test_deliver_walks_chain_and_replays_safely(): void
    {
        $dispatcher = $this->dispatcher();
        $fulfillment = $this->readyFulfillment('e1');
        $shipmentId = $this->createHttp($dispatcher, $fulfillment->id)
            ->assertStatus(201)->json('data.id');

        // Deliver before dispatch → 422 (explicit progression only).
        $this->actingAs($dispatcher, 'sanctum')
            ->postJson("/api/v1/admin/shipments/{$shipmentId}/deliver", [])->assertStatus(422);

        $this->actingAs($dispatcher, 'sanctum')
            ->postJson("/api/v1/admin/shipments/{$shipmentId}/dispatch", [])->assertStatus(200);

        $deliver = $this->actingAs($dispatcher, 'sanctum')
            ->postJson("/api/v1/admin/shipments/{$shipmentId}/deliver", []);
        $deliver->assertStatus(200);
        $this->assertSame('delivered', Shipment::find($shipmentId)->status);
        $this->assertNotNull(Shipment::find($shipmentId)->delivered_at);
        $this->assertSame('delivered', $fulfillment->fresh()->status);

        // Re-delivery → 200 safe replay.
        $this->actingAs($dispatcher, 'sanctum')
            ->postJson("/api/v1/admin/shipments/{$shipmentId}/deliver", [])->assertStatus(200);
        $this->assertSame('delivered', Shipment::find($shipmentId)->status);
    }

    // ---------- cancel ----------

    public function test_cancel_shipment_records_audit_and_never_moves_backward(): void
    {
        $dispatcher = $this->dispatcher();
        $fulfillment = $this->readyFulfillment('x1');
        $shipmentId = $this->createHttp($dispatcher, $fulfillment->id)
            ->assertStatus(201)->json('data.id');

        $cancel = $this->actingAs($dispatcher, 'sanctum')
            ->postJson("/api/v1/admin/shipments/{$shipmentId}/cancel", ['reason' => 'wrong address']);
        $cancel->assertStatus(200);
        $cancelled = Shipment::find($shipmentId);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('wrong address', $cancelled->cancel_reason);
        $this->assertSame('admin_api', $cancelled->cancel_source);
        $this->assertSame($dispatcher->id, (int) $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);

        // Terminal cancel refuses; fulfillment untouched (still ready_to_ship).
        $this->actingAs($dispatcher, 'sanctum')
            ->postJson("/api/v1/admin/shipments/{$shipmentId}/cancel", ['reason' => 'again'])
            ->assertStatus(422);
        $this->assertSame('ready_to_ship', $fulfillment->fresh()->status);

        // Cancel after dispatch (fulfillment shipped) → 422, never backward.
        $f2 = $this->readyFulfillment('x2');
        $s2 = $this->createHttp($dispatcher, $f2->id)->assertStatus(201)->json('data.id');
        $this->actingAs($dispatcher, 'sanctum')
            ->postJson("/api/v1/admin/shipments/{$s2}/dispatch", [])->assertStatus(200);
        $this->actingAs($dispatcher, 'sanctum')
            ->postJson("/api/v1/admin/shipments/{$s2}/cancel", ['reason' => 'too late'])
            ->assertStatus(422);
        $this->assertSame('picked_up', Shipment::find($s2)->status);
        $this->assertSame('shipped', $f2->fresh()->status);
    }

    public function test_shipment_commands_leave_order_lifecycle_untouched(): void
    {
        $dispatcher = $this->dispatcher();
        $fulfillment = $this->readyFulfillment('i1');
        $orderBefore = $fulfillment->order()->first()->only(['status', 'fulfillment_status', 'inventory_state']);

        $shipmentId = $this->createHttp($dispatcher, $fulfillment->id)
            ->assertStatus(201)->json('data.id');
        $this->actingAs($dispatcher, 'sanctum')
            ->postJson("/api/v1/admin/shipments/{$shipmentId}/dispatch", [])->assertStatus(200);
        $this->actingAs($dispatcher, 'sanctum')
            ->postJson("/api/v1/admin/shipments/{$shipmentId}/deliver", [])->assertStatus(200);

        // Fulfillment moves (ready_to_ship → shipped → delivered); the order
        // row lifecycle is untouched by shipment commands here (COD pending
        // order: completion rule correctly does not fire).
        $this->assertSame('delivered', $fulfillment->fresh()->status);
        $this->assertSame($orderBefore, $fulfillment->order()->first()->only(['status', 'fulfillment_status', 'inventory_state']));
    }
}

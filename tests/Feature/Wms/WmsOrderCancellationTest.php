<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
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
 * P9-7: during-fulfillment order cancellation adapter.
 *
 * Sole writer: OrderService::changeOrderStatus (Order Flow authority).
 * The cascade into open fulfillments runs inside that service (P7-1);
 * shipped/terminal fulfillments are never force-cancelled (F8-5).
 * Scope is fail-closed: non-global actors need every non-cancelled
 * fulfillment of the order in their home warehouse.
 * Sequential only (shared MySQL).
 */
class WmsOrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-fulfillment', 'manage-warehouse', 'order.cancel-during-fulfillment',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouseA = Warehouse::create([
            'code' => 'WH-XA', 'name' => 'Cancel A', 'status' => 'active', 'is_default' => true,
        ]);
        $this->warehouseB = Warehouse::create([
            'code' => 'WH-XB', 'name' => 'Cancel B', 'status' => 'active', 'is_default' => false,
        ]);

        $location = Location::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'XA-01',
            'barcode' => 'XA-LOC-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $product = Product::create([
            'name' => 'PX', 'slug' => 'px-' . uniqid(), 'sku' => 'SKU-CANCEL-1',
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

    private function canceller(?int $home = null): User
    {
        return $this->user($home ?? $this->warehouseA->id, ['order.cancel-during-fulfillment']);
    }

    private function makeOrder(): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'OX', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'price' => 1000, 'total_price' => 1000,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => Product::where('sku', 'SKU-CANCEL-1')->firstOrFail()->id,
            'product_name' => 'PX', 'product_sku' => 'SKU-CANCEL-1',
            'product_quantity' => 10, 'product_price' => 100, 'product_total_price' => 1000,
        ]);
        app(\App\Services\OrderFlow\OrderFlowService::class)->assignFlowToOrder($order->refresh(), 'local');

        return $order->refresh();
    }

    private function releaseFor(Order $order, int $warehouseId, string $key): Fulfillment
    {
        if ($order->inventory_state === Order::INVENTORY_STATE_NONE) {
            app(OrderReservationService::class)->reserveForOrder($order);
            $order = $order->refresh();
        }

        return app(FulfillmentService::class)->releaseForOrder($order->refresh(), $warehouseId, $key);
    }

    private function cancelHttp(User $actor, int $orderId, array $payload = ['reason' => 'customer changed mind'])
    {
        return $this->actingAs($actor, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$orderId}/cancel", $payload);
    }

    // ---------- auth / permission ----------

    public function test_cancel_unauthenticated_returns_401(): void
    {
        $this->postJson('/api/v1/admin/orders/1/cancel', ['reason' => 'x'])->assertStatus(401);
    }

    public function test_cancel_requires_granular_permission(): void
    {
        $order = $this->makeOrder();
        $this->releaseFor($order, $this->warehouseA->id, 'perm-1');

        // Same warehouse, no granular perm → 403, order untouched.
        $plain = $this->user($this->warehouseA->id, ['view-fulfillment']);
        $this->cancelHttp($plain, $order->id)->assertStatus(403);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('pending', Fulfillment::where('order_id', $order->id)->first()->status);
    }

    public function test_cancel_missing_order_returns_404(): void
    {
        $this->cancelHttp($this->canceller(), 999999)->assertStatus(404);
    }

    public function test_cancel_requires_reason(): void
    {
        $order = $this->makeOrder();
        $this->releaseFor($order, $this->warehouseA->id, 'reason-1');

        $this->cancelHttp($this->canceller(), $order->id, [])->assertStatus(422);
        $this->assertSame('pending', $order->fresh()->status);
    }

    // ---------- happy path + cascade ----------

    public function test_cancel_during_picking_cascades_through_fulfillment_authority(): void
    {
        $order = $this->makeOrder();
        $fulfillment = $this->releaseFor($order, $this->warehouseA->id, 'happy-1');

        $response = $this->cancelHttp($this->canceller(), $order->id);
        $response->assertStatus(200);
        $this->assertSame('Order cancelled successfully.', $response->json('message'));

        $this->assertSame('cancelled', $order->fresh()->status);
        $fresh = $fulfillment->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('order_cancel', $fresh->cancel_source);
        $this->assertNotNull($fresh->cancelled_at);

        $data = $response->json('data');
        $this->assertSame($order->id, (int) $data['order_id']);
        $this->assertSame('cancelled', $data['status']);
        $this->assertSame([['id' => $fulfillment->id, 'status' => 'cancelled']], $data['fulfillments']);
    }

    public function test_cancel_during_packing_cancels_packing_fulfillment(): void
    {
        $order = $this->makeOrder();
        $fulfillment = $this->releaseFor($order, $this->warehouseA->id, 'pack-1');
        $t = app(FulfillmentTransition::class);
        foreach (['picking', 'picked', 'packing'] as $state) {
            $t->transition($fulfillment, $state);
            $fulfillment = $fulfillment->refresh();
        }

        $this->cancelHttp($this->canceller(), $order->id)->assertStatus(200);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', $fulfillment->fresh()->status);
    }

    // ---------- scope ----------

    public function test_cancel_cross_warehouse_denied_with_state_untouched(): void
    {
        $order = $this->makeOrder();
        $this->releaseFor($order, $this->warehouseA->id, 'scope-1');

        // Actor homed in B with the perm: order fulfills from A → 403.
        $foreign = $this->canceller($this->warehouseB->id);
        $this->cancelHttp($foreign, $order->id)->assertStatus(403);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('pending', Fulfillment::where('order_id', $order->id)->first()->status);
    }

    public function test_cancel_without_fulfillment_context_denied_for_scoped_actor(): void
    {
        $order = $this->makeOrder();

        // No fulfillment rows at all → no warehouse nexus → 403.
        $this->cancelHttp($this->canceller(), $order->id)->assertStatus(403);
        $this->assertSame('pending', $order->fresh()->status);

        // Global operator may cancel: no cascade, order still moves.
        $global = $this->user(null, ['manage-warehouse', 'order.cancel-during-fulfillment']);
        $this->cancelHttp($global, $order->id)->assertStatus(200);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    // ---------- shipment boundary ----------

    public function test_cancel_with_shipped_fulfillment_keeps_shipment_open(): void
    {
        $order = $this->makeOrder();
        $fulfillment = $this->releaseFor($order, $this->warehouseA->id, 'ship-1');
        $t = app(FulfillmentTransition::class);
        foreach (['picking', 'picked', 'packing', 'ready_to_ship', 'shipped'] as $state) {
            $t->transition($fulfillment, $state);
            $fulfillment = $fulfillment->refresh();
        }

        // Order cancel commits (Phase-7 rule) but the shipped fulfillment is
        // never force-cancelled: operations must void labels via shipment
        // cancel instead of silently orphaning them.
        $this->cancelHttp($this->canceller(), $order->id)->assertStatus(200);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('shipped', $fulfillment->fresh()->status);
    }
}

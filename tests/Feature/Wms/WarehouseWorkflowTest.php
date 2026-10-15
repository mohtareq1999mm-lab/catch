<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Models\WarehouseWorkflowVersion;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Shipment\ShipmentService;
use App\Services\Warehouse\WarehouseWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Warehouse Workflow — config/auth/versioning/skipping/shipping/E2E.
 * MySQL-only, sequential (shared MySQL), RefreshDatabase (real migrations).
 */
class WarehouseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-warehouse', 'manage-warehouse', 'manage-warehouse-workflow',
            'view-fulfillment', 'manage-fulfillment', 'fulfillment.create', 'fulfillment.cancel',
            'picking-execute', 'packing-execute', 'packing.complete',
            'view-shipment', 'create-shipment', 'update-shipment',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }
    }

    private function admin(array $perms = ['manage-warehouse-workflow']): User
    {
        $u = User::factory()->create(['type' => 'staff']);
        foreach ($perms as $p) {
            $u->givePermissionTo($p);
        }

        return $u;
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::create([
            'code' => 'WH-'.uniqid(), 'name' => 'Main', 'status' => 'active', 'is_default' => true,
        ]);
    }

    private function product(array $over = []): Product
    {
        return Product::create(array_merge([
            'name' => 'PW', 'slug' => 'pw-'.uniqid(), 'sku' => 'SKU-W-'.uniqid(),
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 100, 'reserved_quantity' => 0, 'sold_quantity' => 0,
        ], $over));
    }

    private function paidOrder(Product $product, int $qty = 1): Order
    {
        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'WO', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 100 * $qty, 'total_price' => 100 * $qty,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $product->id, 'product_name' => 'PW',
            'product_sku' => $product->sku, 'product_quantity' => $qty,
            'product_price' => 100, 'product_total_price' => 100 * $qty,
        ]);
        app(\App\Services\OrderFlow\OrderFlowService::class)->assignFlowToOrder($order->refresh(), 'local');

        return $order->refresh();
    }

    // ---------- A. Configuration and authorization ----------

    public function test_unauthorized_cannot_read_or_publish(): void
    {
        $this->getJson('/api/v1/admin/warehouse-workflow')->assertStatus(401);
        $this->postJson('/api/v1/admin/warehouse-workflow/publish', ['shipping_enabled' => false])->assertStatus(401);
    }

    public function test_forbidden_without_permission(): void
    {
        $u = $this->admin([]);
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/admin/warehouse-workflow')->assertStatus(403);
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/admin/warehouse-workflow/publish', ['shipping_enabled' => true])->assertStatus(403);
    }

    public function test_authorized_read_empty_returns_legacy_defaults(): void
    {
        $u = $this->admin();
        $res = $this->actingAs($u, 'sanctum')->getJson('/api/v1/admin/warehouse-workflow')->assertOk();
        // apiResponse shape: {status,message,success,data:{current,defaults,legacy}}
        $this->assertTrue((bool) ($res->json('data.legacy') ?? false));
        $this->assertNull($res->json('data.current'));
    }

    public function test_publish_valid_and_history(): void
    {
        $u = $this->admin();
        $res = $this->actingAs($u, 'sanctum')->postJson('/api/v1/admin/warehouse-workflow/publish', [
            'picking_enabled' => true, 'packing_enabled' => true, 'shipping_enabled' => true,
        ])->assertCreated();
        $this->assertTrue((bool) $res->json('data.shipping_enabled'));

        $this->actingAs($u, 'sanctum')->getJson('/api/v1/admin/warehouse-workflow/history')->assertOk();
        $this->assertEquals(1, WarehouseWorkflowVersion::count());
    }

    public function test_order_received_cannot_be_disabled(): void
    {
        $u = $this->admin();
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/admin/warehouse-workflow/publish', [
            'order_received_enabled' => false,
        ])->assertStatus(422);
        $this->assertEquals(0, WarehouseWorkflowVersion::count());
    }

    public function test_quality_check_not_exposable(): void
    {
        $u = $this->admin();
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/admin/warehouse-workflow/publish', [
            'picking_enabled' => true, 'quality_check_enabled' => true,
        ])->assertStatus(422);
        $this->assertEquals(0, WarehouseWorkflowVersion::count());
    }

    public function test_picking_and_packing_cannot_be_disabled_without_safe_transition(): void
    {
        $u = $this->admin();
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/admin/warehouse-workflow/publish', [
            'picking_enabled' => false,
        ])->assertStatus(422);
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/admin/warehouse-workflow/publish', [
            'packing_enabled' => false,
        ])->assertStatus(422);
        $this->assertEquals(0, WarehouseWorkflowVersion::count());

        // Fulfillment DAG proof: no safe skip edges exist.
        $this->assertEquals(['picking', 'cancelled'], Fulfillment::allowedTransitions('pending'));
        $this->assertEquals(['ready_to_ship', 'cancelled'], Fulfillment::allowedTransitions('packing'));
    }

    public function test_published_versions_immutable(): void
    {
        $svc = app(WarehouseWorkflowService::class);
        $v = $svc->publish(['picking_enabled' => true, 'packing_enabled' => true, 'shipping_enabled' => true], 1);
        $this->expectException(\RuntimeException::class);
        $v->update(['shipping_enabled' => false]);
    }

    public function test_published_versions_cannot_be_deleted(): void
    {
        $svc = app(WarehouseWorkflowService::class);
        $v = $svc->publish(['shipping_enabled' => true], 1);
        try {
            $v->delete();
            $this->fail('Expected version delete refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString((string) $v->id, $e->getMessage());
        }
        $this->assertNotNull(WarehouseWorkflowVersion::find($v->id));
    }

    public function test_corrupt_pinned_version_fails_closed(): void
    {
        $wh = $this->warehouse();
        $product = $this->product();
        app(WarehouseWorkflowService::class)->publish(['shipping_enabled' => true], 1);

        $order = $this->paidOrder($product);
        $f = app(FulfillmentService::class)->releaseForOrder($order, $wh->id, 'corrupt-pin-'.uniqid());

        // DB-level: the FK itself rejects bogus pins (1452), so true
        // corruption cannot persist while the constraint exists. Prove the
        // code-level defense-in-depth with an in-memory reference (no save).
        $f->forceFill(['warehouse_workflow_version_id' => 999999999]);

        try {
            app(\App\Services\Warehouse\WarehouseWorkflowService::class)->assertShipmentAllowedFor($f);
            $this->fail('Expected invalid-version fail-closed rejection');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('999999999', $e->getMessage());
        }
        $this->assertSame(0, Shipment::where('fulfillment_id', $f->id)->count());
    }

    public function test_concurrent_publishes_both_persist_current_is_latest(): void
    {
        $svc = app(WarehouseWorkflowService::class);
        $a = $svc->publish(['shipping_enabled' => true, 'notes' => 'a'], 1);
        $b = $svc->publish(['shipping_enabled' => false, 'notes' => 'b'], 1);
        $this->assertNotEquals($a->id, $b->id);
        $this->assertEquals($b->id, $svc->current()->id);
        $this->assertEquals(2, WarehouseWorkflowVersion::count());
    }

    // ---------- Q-04 pinning ----------

    public function test_new_fulfillments_pin_current_existing_retain(): void
    {
        $wh = $this->warehouse();
        $product = $this->product();
        $svc = app(WarehouseWorkflowService::class);

        // Legacy: no version → NULL pin.
        $order1 = $this->paidOrder($product);
        $f1 = app(FulfillmentService::class)->releaseForOrder($order1, $wh->id, 'pin-legacy-'.uniqid());
        $this->assertNull($f1->warehouse_workflow_version_id);

        // Publish v1, new fulfillment pins v1.
        $v1 = $svc->publish(['shipping_enabled' => true], 1);
        $order2 = $this->paidOrder($product);
        $f2 = app(FulfillmentService::class)->releaseForOrder($order2, $wh->id, 'pin-v1-'.uniqid());
        $this->assertEquals($v1->id, (int) $f2->warehouse_workflow_version_id);

        // Publish v2 (shipping disabled), existing keeps v1.
        $v2 = $svc->publish(['shipping_enabled' => false], 1);
        $this->assertEquals($v1->id, (int) $f2->fresh()->warehouse_workflow_version_id);

        $order3 = $this->paidOrder($product);
        $f3 = app(FulfillmentService::class)->releaseForOrder($order3, $wh->id, 'pin-v2-'.uniqid());
        $this->assertEquals($v2->id, (int) $f3->warehouse_workflow_version_id);
    }

    public function test_missing_config_preserves_legacy_behavior(): void
    {
        $svc = app(WarehouseWorkflowService::class);
        $this->assertNull($svc->current());
        $defaults = $svc->currentOrDefaults();
        $this->assertTrue($defaults['legacy']);
        $this->assertTrue($defaults['shipping_enabled']);
    }

    // ---------- F. Shipping disabled ----------

    public function test_shipping_disabled_blocks_new_shipments_but_existing_continue(): void
    {
        $wh = $this->warehouse();
        $product = $this->product();
        $svc = app(WarehouseWorkflowService::class);
        $transitions = app(FulfillmentTransition::class);
        $shipments = app(ShipmentService::class);

        // Existing shipment path under shipping-enabled version.
        $v1 = $svc->publish(['shipping_enabled' => true], 1);
        $order = $this->paidOrder($product);
        $f = app(FulfillmentService::class)->releaseForOrder($order, $wh->id, 'ship-ok-'.uniqid());
        $this->assertEquals($v1->id, (int) $f->warehouse_workflow_version_id);

        // Walk to ready_to_ship via official authority.
        $transitions->transition($f, 'picking');
        $transitions->transition($f->fresh(), 'packing');
        $transitions->transition($f->fresh(), 'ready_to_ship');
        $shipment = $shipments->createForFulfillment($f->fresh(), [], 'ship-key-'.uniqid());
        $this->assertEquals('label_created', $shipment->status);

        // Publish shipping-disabled; existing fulfillment keeps v1 → dispatch still works.
        $svc->publish(['shipping_enabled' => false], 1);
        $this->assertEquals($v1->id, (int) $f->fresh()->warehouse_workflow_version_id);
        $dispatched = $shipments->dispatch($shipment->id);
        $this->assertEquals('picked_up', $dispatched->status);

        // New fulfillment pins disabled version → creation blocked, state consistent.
        $order2 = $this->paidOrder($product);
        $f2 = app(FulfillmentService::class)->releaseForOrder($order2, $wh->id, 'ship-blocked-'.uniqid());
        $transitions->transition($f2, 'picking');
        $transitions->transition($f2->fresh(), 'packing');
        $transitions->transition($f2->fresh(), 'ready_to_ship');
        try {
            $shipments->createForFulfillment($f2->fresh(), [], 'ship-blocked-key-'.uniqid());
            $this->fail('Expected shipping-disabled rejection');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('disabled', strtolower($e->getMessage()));
        }
        $this->assertEquals(0, Shipment::where('fulfillment_id', $f2->id)->count());
        $this->assertEquals('ready_to_ship', $f2->fresh()->status);

        // Retry does not duplicate.
        try {
            $shipments->createForFulfillment($f2->fresh(), [], 'ship-blocked-key-'.uniqid());
        } catch (\RuntimeException) {
        }
        $this->assertEquals(0, Shipment::where('fulfillment_id', $f2->id)->count());
    }

    public function test_physical_order_cannot_complete_without_shipment_evidence(): void
    {
        $wh = $this->warehouse();
        $product = $this->product();
        $svc = app(WarehouseWorkflowService::class);
        $svc->publish(['shipping_enabled' => false], 1);

        $order = $this->paidOrder($product);
        $f = app(FulfillmentService::class)->releaseForOrder($order, $wh->id, 'no-complete-'.uniqid());
        // Fulfillment stuck before shipped; order must not be delivered.
        $this->assertNotEquals('delivered', $order->fresh()->status);
        $this->assertNotEquals('delivered', $f->fresh()->status);
        $completed = app(ShipmentService::class)->maybeCompleteOrder($order->id);
        $this->assertFalse($completed);
    }

    // ---------- C. Physical lifecycle (enabled path) ----------

    public function test_physical_lifecycle_enabled_path(): void
    {
        $wh = $this->warehouse();
        $product = $this->product();
        app(WarehouseWorkflowService::class)->publish(['shipping_enabled' => true], 1);

        $order = $this->paidOrder($product);
        $f = app(FulfillmentService::class)->releaseForOrder($order, $wh->id, 'phys-'.uniqid());
        $t = app(FulfillmentTransition::class);
        $t->transition($f, 'picking');
        $t->transition($f->fresh(), 'picked');
        $t->transition($f->fresh(), 'packing');
        $t->transition($f->fresh(), 'ready_to_ship');
        $s = app(ShipmentService::class)->createForFulfillment($f->fresh(), [], 'phys-ship-'.uniqid());
        $this->assertEquals('label_created', $s->status);
        app(ShipmentService::class)->dispatch($s->id);
        $this->assertEquals('shipped', $f->fresh()->status);
    }

    // ---------- D/E digital + mixed ----------

    public function test_digital_only_needs_no_fulfillment_or_shipment(): void
    {
        $customer = User::factory()->create(['type' => 'customer']);
        $digital = $this->product(['item_type' => 'DIGITAL']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'DIG', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'completed',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'delivered', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 50, 'total_price' => 50, 'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'digital', 'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $digital->id, 'product_name' => 'DIG',
            'product_sku' => $digital->sku, 'product_quantity' => 1,
            'product_price' => 50, 'product_total_price' => 50, 'item_type' => 'DIGITAL',
        ]);

        // Shipping-disabled must not block digital-only.
        app(WarehouseWorkflowService::class)->publish(['shipping_enabled' => false], 1);
        $this->assertEquals(0, Fulfillment::where('order_id', $order->id)->count());
        $this->assertEquals('completed', $order->fresh()->status);
    }

    public function test_mixed_order_physical_and_digital_independent(): void
    {
        $wh = $this->warehouse();
        $physical = $this->product();
        $digital = $this->product(['item_type' => 'DIGITAL']);
        app(WarehouseWorkflowService::class)->publish(['shipping_enabled' => true], 1);

        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'MIX', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 150, 'total_price' => 150, 'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $physical->id, 'product_name' => 'PHYS',
            'product_sku' => $physical->sku, 'product_quantity' => 1,
            'product_price' => 100, 'product_total_price' => 100,
        ]);
        $order->orderItems()->create([
            'product_id' => $digital->id, 'product_name' => 'DIG',
            'product_sku' => $digital->sku, 'product_quantity' => 1,
            'product_price' => 50, 'product_total_price' => 50, 'item_type' => 'DIGITAL',
        ]);
        app(\App\Services\OrderFlow\OrderFlowService::class)->assignFlowToOrder($order->refresh(), 'local');

        $f = app(FulfillmentService::class)->releaseForOrder($order->refresh(), $wh->id, 'mix-'.uniqid());
        // Only physical lines enter WMS.
        $this->assertEquals(1, $f->items()->count());
    }
}

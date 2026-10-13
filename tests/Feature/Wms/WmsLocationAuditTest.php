<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * WMS Location audit — HTTP/service boundary proofs for F1–F5.
 *
 * DANGER: uses RefreshDatabase. Run ONLY against an isolated test database
 * (CI or a dedicated *_test schema). NEVER against the shared dev MySQL
 * `catch` database — it truncates real tables.
 *
 * Conventions mirror WmsWarehouseLocationTest (permission seeding,
 * sanctum actingAs, warehouse-scoped users).
 */
class WmsLocationAuditTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-warehouse', 'manage-warehouse', 'view-location', 'manage-location',
            'view-fulfillment', 'manage-fulfillment', 'picking-execute', 'inventory-adjust',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create([
            'code' => 'WH-AUDIT', 'name' => 'Audit', 'status' => 'active', 'is_default' => true,
        ]);
    }

    private function manager(): User
    {
        $user = User::factory()->create(['type' => 'staff', 'warehouse_id' => $this->warehouse->id]);
        $user->givePermissionTo(['view-warehouse', 'manage-warehouse', 'view-location', 'manage-location', 'view-fulfillment', 'manage-fulfillment', 'picking-execute', 'inventory-adjust']);

        return $user;
    }

    private function location(string $code, array $overrides = []): Location
    {
        return Location::create(array_merge([
            'warehouse_id' => $this->warehouse->id, 'code' => $code, 'name' => 'Bin ' . $code,
            'type' => Location::TYPE_STORAGE, 'status' => Location::STATUS_ACTIVE,
        ], $overrides));
    }

    // ---------- F1: deletion guards ----------

    public function test_destroy_blocked_by_assigned_task(): void
    {
        $manager = $this->manager();
        $loc = $this->location('DEL-1');
        $placement = ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);
        PickingTask::create([
            'fulfillment_item_id' => $this->fulfillmentItem($placement)->id,
            'product_location_id' => $placement->id,
            'quantity_to_pick' => 2, 'quantity_picked' => 0, 'status' => 'assigned',
        ]);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/locations/{$loc->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);

        $this->assertDatabaseHas('locations', ['id' => $loc->id]);
    }

    public function test_destroy_blocked_by_picking_history_and_suggests_deactivate(): void
    {
        $manager = $this->manager();
        $loc = $this->location('DEL-2');
        $placement = ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);
        PickingTask::create([
            'fulfillment_item_id' => $this->fulfillmentItem($placement)->id,
            'product_location_id' => $placement->id,
            'quantity_to_pick' => 2, 'quantity_picked' => 2, 'status' => 'picked',
        ]);

        // Must 422 with the history message — never 500 on FK RESTRICT.
        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/locations/{$loc->id}")
            ->assertStatus(422);

        // The history-preserving path still works.
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/locations/{$loc->id}/deactivate")
            ->assertStatus(200)
            ->assertJsonFragment(['status' => 'inactive']);
    }

    public function test_destroy_unused_location_still_deletes(): void
    {
        $manager = $this->manager();
        $loc = $this->location('DEL-3');
        // Zero-stock, task-free placement still allows deletion and is cleaned.
        $placement = ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/locations/{$loc->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('locations', ['id' => $loc->id]);
        $this->assertDatabaseMissing('product_locations', ['id' => $placement->id]);
    }

    // ---------- F2: barcode uniqueness ----------

    public function test_duplicate_barcode_returns_422_not_500(): void
    {
        $manager = $this->manager();
        $this->location('BC-1', ['barcode' => 'SCAN-001']);

        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/locations', [
            'warehouse_id' => $this->warehouse->id, 'code' => 'BC-2',
            'name' => 'Dupe scan', 'barcode' => 'SCAN-001',
        ])->assertStatus(422);
    }

    public function test_update_keeps_own_barcode_but_rejects_foreign_duplicate(): void
    {
        $manager = $this->manager();
        $a = $this->location('BC-A', ['barcode' => 'SCAN-A']);
        $this->location('BC-B', ['barcode' => 'SCAN-B']);

        $this->actingAs($manager, 'sanctum')->putJson("/api/v1/admin/locations/{$a->id}", [
            'barcode' => 'SCAN-A',
        ])->assertStatus(200);

        $this->actingAs($manager, 'sanctum')->putJson("/api/v1/admin/locations/{$a->id}", [
            'barcode' => 'SCAN-B',
        ])->assertStatus(422);
    }

    // ---------- F3: shelf identity in task detail ----------

    public function test_task_detail_exposes_shelf_identity(): void
    {
        $manager = $this->manager();
        $loc = $this->location('SHELF-9', ['name' => 'Aisle 9 Bin', 'barcode' => 'SHELF-9-BC']);
        $placement = ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 10, 'allocated_hint' => 0,
        ]);
        $task = PickingTask::create([
            'fulfillment_item_id' => $this->fulfillmentItem($placement)->id,
            'product_location_id' => $placement->id,
            'quantity_to_pick' => 2, 'quantity_picked' => 0, 'status' => 'pending',
        ]);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/admin/picking-tasks/{$task->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.location.code', 'SHELF-9')
            ->assertJsonPath('data.location.name', 'Aisle 9 Bin')
            ->assertJsonPath('data.location.barcode', 'SHELF-9-BC')
            ->assertJsonPath('data.location.status', 'active')
            ->assertJsonPath('data.location.type', Location::TYPE_STORAGE)
            // Legacy id keys preserved for existing callers.
            ->assertJsonPath('data.location.location_id', $loc->id)
            ->assertJsonPath('data.location.warehouse_id', $this->warehouse->id);
    }

    // ---------- F4: record-pick replay ----------

    public function test_record_pick_replay_does_not_double_count(): void
    {
        $manager = $this->manager();
        $placement = ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $this->location('RP-1')->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 10, 'allocated_hint' => 0,
        ]);
        $item = $this->fulfillmentItem($placement);
        $task = PickingTask::create([
            'fulfillment_item_id' => $item->id, 'product_location_id' => $placement->id,
            'quantity_to_pick' => 5, 'quantity_picked' => 0, 'status' => 'assigned',
            'claimed_by' => $manager->id,
        ]);

        $first = $this->actingAs($manager, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$task->id}/record-pick",
            ['quantity' => 2, 'op_seq' => 7]
        );
        $first->assertStatus(200);

        $second = $this->actingAs($manager, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$task->id}/record-pick",
            ['quantity' => 2, 'op_seq' => 7]
        );
        $second->assertStatus(200);

        $this->assertEquals(2, (float) $task->fresh()->quantity_picked);
        $this->assertEquals(2, (float) $item->fresh()->quantity_picked);
        // Replay watermark persisted (confirm() parity).
        $this->assertSame(7, (int) $task->fresh()->op_seq);

        // Legacy keyless path (no op_seq) still records normally.
        $third = $this->actingAs($manager, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$task->id}/record-pick",
            ['quantity' => 1]
        );
        $third->assertStatus(200);
        $this->assertEquals(3, (float) $task->fresh()->quantity_picked);
        $this->assertEquals(3, (float) $item->fresh()->quantity_picked);
    }

    // ---------- F5: transfer blocked under open task ----------

    public function test_transfer_from_task_pinned_placement_is_refused_and_stock_unchanged(): void
    {
        $manager = $this->manager();
        $product = $this->product(['stock_quantity' => 100]);
        $source = $this->location('TR-S');
        $dest = $this->location('TR-D');
        $placement = ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $source->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 60, 'allocated_hint' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $dest->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 40, 'allocated_hint' => 0,
        ]);
        PickingTask::create([
            'fulfillment_item_id' => $this->fulfillmentItem($placement, $product)->id,
            'product_location_id' => $placement->id,
            'quantity_to_pick' => 5, 'quantity_picked' => 0, 'status' => 'picking',
        ]);

        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/stock-transfers', [
            'product_id' => $product->id,
            'source_location_id' => $source->id,
            'destination_location_id' => $dest->id,
            'quantity' => 10,
        ])->assertStatus(422);

        // Central authority + hints provably unchanged by the refusal.
        $this->assertSame(100, (int) $product->fresh()->stock_quantity);
        $this->assertEquals(60, (float) $placement->fresh()->quantity);
    }

    // ---------- helpers ----------

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Audit product',
            'slug' => 'audit-product-' . \Illuminate\Support\Str::random(8),
            'price' => 50,
            'product_type' => \Marvel\Enums\ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'quantity' => 0,
            'stock_quantity' => 10,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ], $overrides));
    }

    private function fulfillmentItem(ProductLocation $placement, ?Product $product = null): FulfillmentItem
    {
        // Single shared order: fulfillment.order_id and the order item's
        // order_id always agree (B2: never fabricate two orders).
        $product ??= $placement->product()->firstOrFail();
        $order = $this->makeOrder($product);
        $fulfillment = Fulfillment::create([
            'order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'fulfillment_number' => 'FUL-AUDIT-' . \Illuminate\Support\Str::random(6),
            'status' => 'pending',
        ]);

        return FulfillmentItem::create([
            'fulfillment_id' => $fulfillment->id,
            'order_item_id' => $order->orderItems()->firstOrFail()->id,
            'product_id' => $product->id,
            'product_location_id' => $placement->id,
            'quantity' => 5,
            'quantity_picked' => 0,
            'status' => 'pending',
        ]);
    }

    /**
     * Established repo pattern (WmsPickingBatchTest::makeOrder): direct
     * Order::create + orderItems()->create — Order/OrderProduct carry no
     * factories (no HasFactory on either model).
     */
    private function makeOrder(Product $product): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'Audit', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 250, 'total_price' => 250,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $product->id, 'product_name' => 'Audit product',
            'product_sku' => 'SKU-AUDIT', 'product_quantity' => 5,
            'product_price' => 50, 'product_total_price' => 250,
        ]);

        return $order->refresh();
    }
}

<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\OrderPickingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * WMS-PICK-001 / WMS-RELEASE-001 regression: create-tasks never returns an
 * empty success; release never resurrects terminal work.
 */
class WmsCreateTasksGuardTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private Product $product;

    private Location $locationA;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-fulfillment', 'fulfillment.create', 'manage-fulfillment',
            'picking-execute', 'packing-execute', 'create-shipment',
            'manage-warehouse',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouseA = Warehouse::create([
            'code' => 'WH-GA', 'name' => 'Guard A', 'status' => 'active', 'is_default' => true,
        ]);
        $this->warehouseB = Warehouse::create([
            'code' => 'WH-GB', 'name' => 'Guard B', 'status' => 'active', 'is_default' => false,
        ]);

        $this->locationA = Location::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'GA-01',
            'barcode' => 'GA-LOC-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'GP', 'slug' => 'gp-' . uniqid(), 'sku' => 'SKU-GUARD-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $this->locationA->id,
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

    private function picker(?int $home = null): User
    {
        return $this->user($home ?? $this->warehouseA->id, ['view-fulfillment', 'picking-execute']);
    }

    private function makeOrder(?Product $product = null, float $qty = 2): Order
    {
        $product ??= $this->product;
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'GO', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'processing',
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
            'product_id' => $product->id, 'product_name' => 'GP',
            'product_sku' => $product->sku, 'product_quantity' => $qty,
            'product_price' => 100, 'product_total_price' => 100 * $qty,
        ]);

        return $order->refresh();
    }

    private function release(Product $product, int $warehouseId, ?string $key = null, float $qty = 2): Fulfillment
    {
        return app(FulfillmentService::class)->releaseForOrder(
            $this->makeOrder($product, $qty), $warehouseId, $key ?? ('gd-' . uniqid())
        );
    }

    public function test_valid_fulfillment_produces_persisted_tasks_and_truthful_response(): void
    {
        $fulfillment = $this->release($this->product, $this->warehouseA->id, 'gd-valid');
        $picker = $this->picker();

        $response = $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        );

        $response->assertStatus(200)->assertJson(['success' => true]);
        $data = $response->json('data');
        $this->assertNotEmpty($data, 'valid fulfillment must return non-empty tasks');

        $taskId = $data[0]['id'];
        $this->assertDatabaseHas('picking_tasks', [
            'id' => $taskId,
            'fulfillment_item_id' => $fulfillment->items()->firstOrFail()->id,
        ]);
        $this->assertSame('picking', $fulfillment->fresh()->status);

        // Subsequent fulfillment read agrees with persisted state.
        $show = $this->actingAs($this->user($this->warehouseA->id, ['view-fulfillment']), 'sanctum')
            ->getJson("/api/v1/admin/fulfillments/{$fulfillment->id}");
        $show->assertStatus(200);
        $this->assertNotEmpty($show->json('data.picking_tasks'));
    }

    public function test_missing_placement_is_422_not_empty_success(): void
    {
        $bare = Product::create([
            'name' => 'GB', 'slug' => 'gb-' . uniqid(), 'sku' => 'SKU-GUARD-BARE',
            'price' => 50, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 20, 'reserved_quantity' => 0,
        ]);
        $fulfillment = $this->release($bare, $this->warehouseA->id, 'gd-bare');
        $this->assertNull($fulfillment->items()->firstOrFail()->product_location_id);

        $picker = $this->picker();
        $response = $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        );

        $response->assertStatus(422);
        $this->assertStringContainsString((string) $fulfillment->id, (string) $response->getContent());
        $this->assertSame(0, PickingTask::whereHas(
            'fulfillmentItem', fn ($q) => $q->where('fulfillment_id', $fulfillment->id)
        )->count());
        $this->assertSame('pending', $fulfillment->fresh()->status);
    }

    public function test_service_throws_on_missing_placement_and_empty_fulfillment(): void
    {
        $bare = Product::create([
            'name' => 'GS', 'slug' => 'gs-' . uniqid(), 'sku' => 'SKU-GUARD-SVC',
            'price' => 50, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 20, 'reserved_quantity' => 0,
        ]);
        $fulfillment = $this->release($bare, $this->warehouseA->id, 'gd-svc');

        try {
            app(OrderPickingService::class)->createTasksForFulfillment($fulfillment);
            $this->fail('missing placement must throw, never return []');
        } catch (\RuntimeException $e) {
            // Locale-proof: message carries the fulfillment id in every locale.
            $this->assertStringContainsString((string) $fulfillment->id, $e->getMessage());
        }

        // Empty fulfillment (no items) throws distinctly.
        $fulfillment->items()->delete();
        try {
            app(OrderPickingService::class)->createTasksForFulfillment($fulfillment->fresh());
            $this->fail('empty fulfillment must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString((string) $fulfillment->id, $e->getMessage());
        }
    }

    public function test_fully_picked_fulfillment_has_no_pickable_work(): void
    {
        $fulfillment = $this->release($this->product, $this->warehouseA->id, 'gd-full');
        $tasks = app(OrderPickingService::class)->createTasksForFulfillment($fulfillment);
        $this->assertNotEmpty($tasks);
        // Simulate completed prior progress on every item.
        foreach ($fulfillment->items()->get() as $item) {
            $item->update(['quantity_picked' => $item->quantity]);
        }
        // Remove open tasks so the only signal is remaining<=0.
        PickingTask::whereHas('fulfillmentItem', fn ($q) => $q->where('fulfillment_id', $fulfillment->id))->delete();

        $picker = $this->picker();
        $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        )->assertStatus(422);
    }

    public function test_invalid_fulfillment_state_is_rejected(): void
    {
        $fulfillment = $this->release($this->product, $this->warehouseA->id, 'gd-state');
        app(OrderPickingService::class)->createTasksForFulfillment($fulfillment);
        // Advance to picked via canonical path would need full picks; force
        // the status through the transition owner to simulate a later stage.
        $fulfillment->refresh()->items()->update(['quantity_picked' => 2]);
        app(\App\Services\Fulfillment\FulfillmentTransition::class)->transition($fulfillment->fresh(), 'picked');
        app(\App\Services\Fulfillment\FulfillmentTransition::class)->transition($fulfillment->fresh(), 'packing');

        $this->actingAs($this->picker(), 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        )->assertStatus(422);
    }

    public function test_repeated_create_tasks_is_idempotent_without_duplicates(): void
    {
        $fulfillment = $this->release($this->product, $this->warehouseA->id, 'gd-idem');
        $picker = $this->picker();

        $first = $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        )->assertStatus(200);
        $second = $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        )->assertStatus(200);

        $this->assertSame(
            collect($first->json('data'))->pluck('id')->sort()->values()->all(),
            collect($second->json('data'))->pluck('id')->sort()->values()->all()
        );
        $this->assertSame(1, PickingTask::whereHas(
            'fulfillmentItem', fn ($q) => $q->where('fulfillment_id', $fulfillment->id)
        )->count());
    }

    public function test_warehouse_isolation_and_auth_are_enforced(): void
    {
        $fulfillment = $this->release($this->product, $this->warehouseA->id, 'gd-scope');

        $this->postJson("/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", [])->assertStatus(401);

        $noPerm = $this->user($this->warehouseA->id, ['view-fulfillment']);
        $this->actingAs($noPerm, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        )->assertStatus(403);

        $foreign = $this->picker($this->warehouseB->id);
        $this->actingAs($foreign, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        )->assertStatus(403);
        $this->assertSame(0, PickingTask::whereHas(
            'fulfillmentItem', fn ($q) => $q->where('fulfillment_id', $fulfillment->id)
        )->count());
    }

    public function test_release_terminal_task_refused_and_state_preserved(): void
    {
        $fulfillment = $this->release($this->product, $this->warehouseA->id, 'gd-rel');
        $task = app(OrderPickingService::class)->createTasksForFulfillment($fulfillment)[0];
        $picker = $this->picker();

        $this->actingAs($picker, 'sanctum')->postJson("/api/v1/admin/picking-tasks/{$task->id}/claim", [])->assertStatus(200);
        $this->actingAs($picker, 'sanctum')->postJson("/api/v1/admin/picking-tasks/{$task->id}/confirm", [
            'location' => 'GA-LOC-01', 'product' => 'SKU-GUARD-1', 'quantity' => 2, 'op_seq' => 1,
        ])->assertStatus(200);
        $this->assertSame('picked', $task->fresh()->status);

        // HTTP refuses terminal release.
        $this->actingAs($picker, 'sanctum')->postJson("/api/v1/admin/picking-tasks/{$task->id}/release", [])->assertStatus(422);
        $this->assertSame('picked', $task->fresh()->status);

        // Service boundary also refuses (no resurrection via direct calls).
        try {
            app(\App\Services\Fulfillment\PickingExecutionService::class)->releaseClaim($task->fresh(), $picker->id, true);
            $this->fail('service must refuse terminal release even with override');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString((string) $task->id, $e->getMessage());
        }
        $this->assertSame('picked', $task->fresh()->status);
    }

    public function test_release_foreign_claim_is_forbidden(): void
    {
        $fulfillment = $this->release($this->product, $this->warehouseA->id, 'gd-foreign');
        $task = app(OrderPickingService::class)->createTasksForFulfillment($fulfillment)[0];
        $picker = $this->picker();
        $other = $this->user($this->warehouseA->id, ['view-fulfillment', 'picking-execute']);

        $this->actingAs($picker, 'sanctum')->postJson("/api/v1/admin/picking-tasks/{$task->id}/claim", [])->assertStatus(200);
        $this->actingAs($other, 'sanctum')->postJson("/api/v1/admin/picking-tasks/{$task->id}/release", [])->assertStatus(403);
        $this->assertSame($picker->id, (int) $task->fresh()->claimed_by);
    }

    public function test_partial_allocation_returns_created_subset_and_leaves_remainder_diagnosable(): void
    {
        // Two-line order: first line allocated, second line has no placement.
        $bare = Product::create([
            'name' => 'GH', 'slug' => 'gh-' . uniqid(), 'sku' => 'SKU-GUARD-PARTIAL-BARE',
            'price' => 50, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 20, 'reserved_quantity' => 0,
        ]);
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'GH', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 150, 'total_price' => 150, 'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'GP',
            'product_sku' => $this->product->sku, 'product_quantity' => 1,
            'product_price' => 100, 'product_total_price' => 100,
        ]);
        $order->orderItems()->create([
            'product_id' => $bare->id, 'product_name' => 'GH',
            'product_sku' => $bare->sku, 'product_quantity' => 1,
            'product_price' => 50, 'product_total_price' => 50,
        ]);
        $fulfillment = app(FulfillmentService::class)->releaseForOrder(
            $order->refresh(), $this->warehouseA->id, 'gd-partial'
        );
        $this->assertCount(2, $fulfillment->items);

        $picker = $this->picker();
        $response = $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        );
        // Progressive picking: allocated subset succeeds (envelope preserved),
        // remainder stays diagnosable via the fulfillment detail (null placement).
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, PickingTask::whereHas(
            'fulfillmentItem', fn ($q) => $q->where('fulfillment_id', $fulfillment->id)
        )->count());

        $detail = $this->actingAs($this->user($this->warehouseA->id, ['view-fulfillment']), 'sanctum')
            ->getJson("/api/v1/admin/fulfillments/{$fulfillment->id}");
        $detail->assertStatus(200);
        $nullPlacements = collect($detail->json('data.items'))->whereNull('product_location_id')->count();
        $this->assertSame(1, $nullPlacements, 'remainder must remain visible as null placement');
    }

    public function test_variant_product_resolves_correct_sku_and_placement(): void
    {
        $parent = Product::create([
            'name' => 'GV', 'slug' => 'gv-' . uniqid(), 'sku' => 'SKU-GUARD-VAR',
            'price' => 200, 'product_type' => 'variable', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 0, 'reserved_quantity' => 0,
        ]);
        $variant = \Marvel\Database\Models\ProductVariant::create([
            'product_id' => $parent->id, 'sku' => 'SKU-GUARD-VAR-S',
            'price' => 200, 'stock_quantity' => 20, 'reserved_quantity' => 0,
            'sold_quantity' => 0, 'in_stock' => true,
        ]);
        $placement = ProductLocation::create([
            'product_id' => $parent->id, 'product_variant_id' => $variant->id,
            'location_id' => $this->locationA->id, 'warehouse_id' => $this->warehouseA->id,
            'quantity' => 20, 'allocated_hint' => 0,
        ]);

        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'GV', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 200, 'total_price' => 200, 'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $parent->id, 'product_variant_id' => $variant->id,
            'product_name' => 'GV', 'product_sku' => $variant->sku, 'product_quantity' => 2,
            'product_price' => 200, 'product_total_price' => 400,
        ]);

        $fulfillment = app(FulfillmentService::class)->releaseForOrder(
            $order->refresh(), $this->warehouseA->id, 'gd-variant'
        );
        $item = $fulfillment->items()->firstOrFail();
        $this->assertSame($variant->id, (int) $item->product_variant_id);
        $this->assertSame($placement->id, (int) $item->product_location_id);

        $picker = $this->picker();
        $response = $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        );
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
        $task = PickingTask::findOrFail((int) $response->json('data.0.id'));
        $this->assertSame($placement->id, (int) $task->product_location_id);
        $this->assertSame($variant->id, (int) $task->fulfillmentItem->product_variant_id);
    }
}

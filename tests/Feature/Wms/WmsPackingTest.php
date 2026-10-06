<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\Package;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\PackingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\BatchPickingService;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\OrderPickingService;
use App\Services\Fulfillment\PickingExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * P9-6: packing-task + station + package HTTP adapter.
 *
 * Thin adapter over PackingService: permission (route) + warehouse scope
 * (task → fulfillment.warehouse_id, station → warehouse_id, package →
 * fulfillment.warehouse_id) + validation. Packing → fulfillment moves run
 * inside the service via the transition owner. Shipment creation from a
 * verified task is P9-8 territory and is NOT covered here.
 * Sequential only (shared MySQL).
 */
class WmsPackingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private Product $product;

    private PackingStation $stationA;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-warehouse', 'view-location', 'view-fulfillment', 'manage-fulfillment',
            'picking-execute', 'packing-execute', 'packing.complete',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouseA = Warehouse::create([
            'code' => 'WH-KA', 'name' => 'Pack A', 'status' => 'active', 'is_default' => true,
        ]);
        $this->warehouseB = Warehouse::create([
            'code' => 'WH-KB', 'name' => 'Pack B', 'status' => 'active', 'is_default' => false,
        ]);

        $location = Location::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'KA-01',
            'barcode' => 'KA-LOC-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'PK', 'slug' => 'pk-' . uniqid(), 'sku' => 'SKU-PACK-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $location->id,
            'warehouse_id' => $this->warehouseA->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);

        $this->stationA = PackingStation::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'ST-A1',
            'name' => 'Station A1', 'status' => 'active',
        ]);
        PackingStation::create([
            'warehouse_id' => $this->warehouseB->id, 'code' => 'ST-B1',
            'name' => 'Station B1', 'status' => 'active',
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

    private function packer(?int $home = null): User
    {
        return $this->user($home ?? $this->warehouseA->id, [
            'view-warehouse', 'view-location', 'view-fulfillment', 'packing-execute',
        ]);
    }

    private function supervisor(?int $home = null): User
    {
        return $this->user($home ?? $this->warehouseA->id, [
            'view-warehouse', 'view-location', 'view-fulfillment', 'manage-fulfillment',
            'picking-execute', 'packing-execute', 'packing.complete',
        ]);
    }

    private function makeOrder(): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'KO', 'user_email' => $user->email,
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
            'product_id' => $this->product->id, 'product_name' => 'PK',
            'product_sku' => 'SKU-PACK-1', 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);

        return $order->refresh();
    }

    /**
     * Release → pick (claim + full record) → picked, via domain services.
     */
    private function pickedFulfillment(?string $key = null): Fulfillment
    {
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($this->makeOrder(), $this->warehouseA->id, $key ?? ('pk-' . uniqid()));
        $worker = $this->packer();
        foreach (app(OrderPickingService::class)->createTasksForFulfillment($fulfillment) as $task) {
            app(PickingExecutionService::class)->claim($task, $worker->id);
            app(BatchPickingService::class)->recordPick($task->fresh(), 2, null, $worker->id);
        }

        return app(OrderPickingService::class)->completePicking($fulfillment->fresh())->fresh();
    }

    private function fullPackingTask(User $supervisor, ?Fulfillment $fulfillment = null): PackingTask
    {
        $fulfillment ??= $this->pickedFulfillment();
        $taskId = $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/fulfillments/{$fulfillment->id}/create-packing-task", [])
            ->assertStatus(201)->json('data.id');
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$taskId}/assign", ['station_id' => $this->stationA->id])
            ->assertStatus(200);
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$taskId}/start", [])
            ->assertStatus(200);

        return PackingTask::findOrFail($taskId);
    }

    private function packDims(): array
    {
        return ['weight' => 2.5, 'dimensions' => ['length' => 30, 'width' => 20, 'height' => 10]];
    }

    // ---------- auth ----------

    public function test_unauthenticated_packing_endpoints_return_401(): void
    {
        $this->getJson('/api/v1/admin/packing-tasks')->assertStatus(401);
        $this->getJson('/api/v1/admin/packing-tasks/1')->assertStatus(401);
        $this->postJson('/api/v1/admin/fulfillments/1/create-packing-task', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/packing-tasks/1/assign', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/packing-tasks/1/start', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/packing-tasks/1/pack', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/packing-tasks/1/verify', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/packing-tasks/1/cancel', [])->assertStatus(401);
        $this->getJson('/api/v1/admin/packing-stations')->assertStatus(401);
        $this->getJson('/api/v1/admin/packages?fulfillment_id=1')->assertStatus(401);
        $this->postJson('/api/v1/admin/packages', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/packages/1/add-item', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/packages/1/seal', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/packages/1/void', [])->assertStatus(401);
    }

    // ---------- reads + scope ----------

    public function test_packing_reads_scope_to_home_warehouse(): void
    {
        $fulfillment = $this->pickedFulfillment();
        $packer = $this->packer();
        $taskId = $this->actingAs($this->supervisor(), 'sanctum')
            ->postJson("/api/v1/admin/fulfillments/{$fulfillment->id}/create-packing-task", [])
            ->assertStatus(201)->json('data.id');

        $list = $this->actingAs($packer, 'sanctum')->getJson('/api/v1/admin/packing-tasks');
        $list->assertStatus(200);
        $this->assertNotEmpty($list->json('data'));

        $this->actingAs($packer, 'sanctum')
            ->getJson("/api/v1/admin/packing-tasks/{$taskId}")->assertStatus(200);

        // Foreign-homed packer probing task + fulfillment filter → 404.
        $foreign = $this->packer($this->warehouseB->id);
        $this->actingAs($foreign, 'sanctum')
            ->getJson("/api/v1/admin/packing-tasks/{$taskId}")->assertStatus(404);
        $this->actingAs($foreign, 'sanctum')
            ->getJson("/api/v1/admin/packing-tasks?fulfillment_id={$fulfillment->id}")->assertStatus(404);

        // Stations: home only; foreign filter → 404.
        $stations = $this->actingAs($packer, 'sanctum')->getJson('/api/v1/admin/packing-stations');
        $stations->assertStatus(200);
        $this->assertSame(['ST-A1'], array_column($stations->json('data'), 'code'));
        $this->actingAs($packer, 'sanctum')
            ->getJson("/api/v1/admin/packing-stations?warehouse_id={$this->warehouseB->id}")->assertStatus(404);

        // Packages: fulfillment_id required; foreign fulfillment → 404.
        $this->actingAs($packer, 'sanctum')->getJson('/api/v1/admin/packages')->assertStatus(422);
        $this->actingAs($packer, 'sanctum')
            ->getJson("/api/v1/admin/packages?fulfillment_id={$fulfillment->id}")->assertStatus(200);
        $this->actingAs($foreign, 'sanctum')
            ->getJson("/api/v1/admin/packages?fulfillment_id={$fulfillment->id}")->assertStatus(404);
    }

    // ---------- permission split ----------

    public function test_pack_complete_verify_cancel_void_require_elevated_permissions(): void
    {
        $supervisor = $this->supervisor();
        $task = $this->fullPackingTask($supervisor);
        $packer = $this->packer();

        // Packer executes but cannot complete/verify/cancel/void.
        $this->actingAs($packer, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$task->id}/pack", $this->packDims())->assertStatus(403);
        $this->actingAs($packer, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$task->id}/verify", [])->assertStatus(403);
        $this->actingAs($packer, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$task->id}/cancel", ['reason' => 'x'])->assertStatus(403);

        $packageId = $this->actingAs($supervisor, 'sanctum')
            ->postJson('/api/v1/admin/packages', ['fulfillment_id' => $task->fulfillment_id])
            ->assertStatus(201)->json('data.id');
        $this->actingAs($packer, 'sanctum')
            ->postJson("/api/v1/admin/packages/{$packageId}/void", ['reason' => 'x'])->assertStatus(403);

        $this->assertSame('packing', $task->fresh()->status);
        $this->assertSame('open', Package::find($packageId)->status);
    }

    // ---------- task lifecycle ----------

    public function test_create_packing_task_guards_status_and_single_open_task(): void
    {
        $supervisor = $this->supervisor();
        $fulfillment = $this->pickedFulfillment();

        $taskId = $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/fulfillments/{$fulfillment->id}/create-packing-task", [])
            ->assertStatus(201)->json('data.id');
        $this->assertSame('packing', $fulfillment->fresh()->status);

        // Second open task → 409, no duplicate row.
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/fulfillments/{$fulfillment->id}/create-packing-task", [])
            ->assertStatus(409);
        $this->assertSame(1, PackingTask::where('fulfillment_id', $fulfillment->id)->count());

        // Foreign fulfillment → 403.
        $foreign = $this->supervisor($this->warehouseB->id);
        $this->actingAs($foreign, 'sanctum')
            ->postJson("/api/v1/admin/fulfillments/{$fulfillment->id}/create-packing-task", [])
            ->assertStatus(403);

        // Wrong-status fulfillment (fresh release, still picking setup) → 422.
        $fresh = app(FulfillmentService::class)
            ->releaseForOrder($this->makeOrder(), $this->warehouseA->id, 'pk-wrong-' . uniqid());
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/fulfillments/{$fresh->id}/create-packing-task", [])
            ->assertStatus(422);
        $this->assertSame(1, PackingTask::where('fulfillment_id', $fulfillment->id)->count());
    }

    public function test_assign_pins_caller_and_refuses_occupied_and_foreign_station(): void
    {
        $supervisor = $this->supervisor();
        $fulfillment = $this->pickedFulfillment();
        $taskId = $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/fulfillments/{$fulfillment->id}/create-packing-task", [])
            ->assertStatus(201)->json('data.id');

        $response = $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$taskId}/assign", ['station_id' => $this->stationA->id]);
        $response->assertStatus(200);
        $task = PackingTask::find($taskId);
        $this->assertSame($supervisor->id, (int) $task->assigned_to);
        $this->assertSame('assigned', $task->status);

        // Occupied task claimed by another supervisor → 409, winner kept.
        $other = $this->supervisor();
        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$taskId}/assign", ['station_id' => $this->stationA->id])
            ->assertStatus(409);
        $this->assertSame($supervisor->id, (int) $task->fresh()->assigned_to);

        // Foreign-warehouse station → 422, assignment untouched.
        $stationB = PackingStation::where('code', 'ST-B1')->firstOrFail();
        $task2 = $this->fullPackingTask($this->supervisor(), $this->pickedFulfillment('st2'));
        // task2 already assigned; use a fresh pending task for the foreign-station probe.
        $fresh = $this->pickedFulfillment('st3');
        $freshTaskId = $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/fulfillments/{$fresh->id}/create-packing-task", [])
            ->assertStatus(201)->json('data.id');
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$freshTaskId}/assign", ['station_id' => $stationB->id])
            ->assertStatus(422);
        $this->assertSame('pending', PackingTask::find($freshTaskId)->status);
    }

    public function test_start_guards_state(): void
    {
        $supervisor = $this->supervisor();
        $task = $this->fullPackingTask($supervisor);
        $this->assertSame('packing', $task->fresh()->status);

        // Restart started task → 422.
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$task->id}/start", [])->assertStatus(422);
    }

    public function test_pack_refuses_double_completion_without_overwrite(): void
    {
        $supervisor = $this->supervisor();
        $task = $this->fullPackingTask($supervisor);

        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$task->id}/pack", $this->packDims())
            ->assertStatus(200);
        $this->assertSame('packed', $task->fresh()->status);

        // Second completion → 422, original weight survives.
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$task->id}/pack", [
                'weight' => 9.9, 'dimensions' => ['length' => 1],
            ])->assertStatus(422);
        $this->assertEquals(2.5, (float) $task->fresh()->weight);
    }

    public function test_verify_requires_full_packaging_then_readies_shipment(): void
    {
        $supervisor = $this->supervisor();
        $fulfillment = $this->pickedFulfillment();
        $task = $this->fullPackingTask($supervisor, $fulfillment);
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$task->id}/pack", $this->packDims())
            ->assertStatus(200);

        // Nothing packaged yet → 422, fulfillment stays packing.
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$task->id}/verify", [])->assertStatus(422);
        $this->assertSame('packing', $fulfillment->fresh()->status);

        // Package the full picked quantity, seal, then verify.
        $packageId = $this->actingAs($supervisor, 'sanctum')
            ->postJson('/api/v1/admin/packages', ['fulfillment_id' => $fulfillment->id])
            ->assertStatus(201)->json('data.id');
        $itemId = $fulfillment->items()->firstOrFail()->id;
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packages/{$packageId}/add-item", [
                'fulfillment_item_id' => $itemId, 'quantity' => 2,
            ])->assertStatus(200);
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packages/{$packageId}/seal", [])->assertStatus(200);

        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$task->id}/verify", ['notes' => 'ok'])
            ->assertStatus(200);
        $this->assertSame('verified', $task->fresh()->status);
        $this->assertSame('ready_to_ship', $fulfillment->fresh()->status);
    }

    public function test_cancel_task_guards_packed_work(): void
    {
        $supervisor = $this->supervisor();
        $task = $this->fullPackingTask($supervisor);

        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$task->id}/cancel", ['reason' => 'duplicate task'])
            ->assertStatus(200);
        $this->assertSame('cancelled', $task->fresh()->status);

        // Packed task cannot silently downgrade → 422.
        $packed = $this->fullPackingTask($supervisor, $this->pickedFulfillment('cx'));
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$packed->id}/pack", $this->packDims())
            ->assertStatus(200);
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$packed->id}/cancel", ['reason' => 'too late'])
            ->assertStatus(422);
        $this->assertSame('packed', $packed->fresh()->status);
    }

    // ---------- packages ----------

    public function test_package_lifecycle_guards_single_active_and_overpack(): void
    {
        $supervisor = $this->supervisor();
        $fulfillment = $this->pickedFulfillment();
        $itemId = $fulfillment->items()->firstOrFail()->id;

        $packageId = $this->actingAs($supervisor, 'sanctum')
            ->postJson('/api/v1/admin/packages', ['fulfillment_id' => $fulfillment->id])
            ->assertStatus(201)->json('data.id');

        // Second active package → 409.
        $this->actingAs($supervisor, 'sanctum')
            ->postJson('/api/v1/admin/packages', ['fulfillment_id' => $fulfillment->id])
            ->assertStatus(409);
        $this->assertSame(1, Package::where('fulfillment_id', $fulfillment->id)->count());

        // Over-pack beyond picked qty → 422.
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packages/{$packageId}/add-item", [
                'fulfillment_item_id' => $itemId, 'quantity' => 3,
            ])->assertStatus(422);

        // Foreign fulfillment item → 422.
        $other = $this->pickedFulfillment('fr');
        $otherItemId = $other->items()->firstOrFail()->id;
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packages/{$packageId}/add-item", [
                'fulfillment_item_id' => $otherItemId, 'quantity' => 1,
            ])->assertStatus(422);

        // Exact picked quantity → 200; seal assigns barcode.
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packages/{$packageId}/add-item", [
                'fulfillment_item_id' => $itemId, 'quantity' => 2,
            ])->assertStatus(200);
        $seal = $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packages/{$packageId}/seal", []);
        $seal->assertStatus(200);
        $this->assertNotEmpty($seal->json('data.barcode'));

        // Sealed package is immutable: add-item + void both 422.
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packages/{$packageId}/add-item", [
                'fulfillment_item_id' => $itemId, 'quantity' => 1,
            ])->assertStatus(422);
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packages/{$packageId}/void", ['reason' => 'too late'])
            ->assertStatus(422);
    }

    public function test_seal_empty_package_refused_and_void_frees_replacement(): void
    {
        $supervisor = $this->supervisor();
        $fulfillment = $this->pickedFulfillment();

        $packageId = $this->actingAs($supervisor, 'sanctum')
            ->postJson('/api/v1/admin/packages', ['fulfillment_id' => $fulfillment->id])
            ->assertStatus(201)->json('data.id');

        // Empty package cannot seal.
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packages/{$packageId}/seal", [])->assertStatus(422);

        // Void the open package → row retained as history, replacement allowed.
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packages/{$packageId}/void", ['reason' => 'wrong box'])
            ->assertStatus(200);
        $this->assertSame('voided', Package::find($packageId)->status);

        $replacement = $this->actingAs($supervisor, 'sanctum')
            ->postJson('/api/v1/admin/packages', ['fulfillment_id' => $fulfillment->id])
            ->assertStatus(201)->json('data.id');
        $this->assertNotSame($packageId, $replacement);
    }

    public function test_packing_commands_leave_orders_inventory_shipments_untouched(): void
    {
        $supervisor = $this->supervisor();
        $fulfillment = $this->pickedFulfillment();
        $orderBefore = $fulfillment->order()->first()->only(['status', 'fulfillment_status', 'inventory_state']);
        $stockBefore = [(float) $this->product->fresh()->stock_quantity, (float) $this->product->fresh()->reserved_quantity];
        $shipmentsBefore = \App\Models\Shipment::count();

        $task = $this->fullPackingTask($supervisor, $fulfillment);
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$task->id}/pack", $this->packDims())
            ->assertStatus(200);

        $packageId = $this->actingAs($supervisor, 'sanctum')
            ->postJson('/api/v1/admin/packages', ['fulfillment_id' => $fulfillment->id])
            ->assertStatus(201)->json('data.id');
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packages/{$packageId}/add-item", [
                'fulfillment_item_id' => $fulfillment->items()->firstOrFail()->id, 'quantity' => 2,
            ])->assertStatus(200);
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packages/{$packageId}/seal", [])->assertStatus(200);
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/admin/packing-tasks/{$task->id}/verify", [])->assertStatus(200);

        // Only the fulfillment moves (packing → ready_to_ship). Order,
        // inventory, and shipment rows are untouched by packing commands.
        $this->assertSame('ready_to_ship', $fulfillment->fresh()->status);
        $this->assertSame($orderBefore, $fulfillment->order()->first()->only(['status', 'fulfillment_status', 'inventory_state']));
        $this->assertSame($stockBefore, [(float) $this->product->fresh()->stock_quantity, (float) $this->product->fresh()->reserved_quantity]);
        $this->assertSame($shipmentsBefore, \App\Models\Shipment::count());
    }
}

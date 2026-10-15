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
 * WMS-PICK-001 / WMS-RELEASE-001 regression: create-tasks truthful contract.
 *
 * - Valid fulfillment → 200 + persisted tasks, response agrees with DB.
 * - Missing placement → 422 awaiting placement, zero tasks created.
 * - Empty fulfillment → 422, never 200+data:[].
 * - Invalid lifecycle state → 422.
 * - Fully-picked (no remaining) → 422 no_tasks.
 * - Idempotent replay → 200 same IDs, no duplicates.
 * - Release terminal → 422, no resurrection.
 */
class WmsCreateTasksContractTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-warehouse', 'manage-warehouse', 'view-location', 'manage-location',
            'view-fulfillment', 'manage-fulfillment',
            'picking-execute', 'packing-execute', 'packing.complete',
            'batch.manage', 'batch.operate',
            'view-shipment', 'create-shipment', 'update-shipment',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create([
            'code' => 'WH-CT', 'name' => 'Contract WH', 'status' => 'active', 'is_default' => true,
        ]);
        $this->location = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'CT-01',
            'barcode' => 'CT-LOC-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'CT', 'slug' => 'ct-'.uniqid(), 'sku' => 'SKU-CT-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 100, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $this->location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'allocated_hint' => 0,
        ]);
    }

    private function user(array $permissions): User
    {
        $user = User::factory()->create(['type' => 'staff', 'warehouse_id' => $this->warehouse->id]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function picker(): User
    {
        return $this->user(['view-fulfillment', 'picking-execute']);
    }

    private function makeOrder(): Order
    {
        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'CT', 'user_email' => $customer->email,
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
            'product_id' => $this->product->id, 'product_name' => 'CT',
            'product_sku' => 'SKU-CT-1', 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);

        return $order->refresh();
    }

    private function release(?string $key = null): Fulfillment
    {
        return app(FulfillmentService::class)->releaseForOrder(
            $this->makeOrder(), $this->warehouse->id, $key ?? ('ct-'.uniqid())
        );
    }

    public function test_valid_fulfillment_produces_persisted_tasks(): void
    {
        $fulfillment = $this->release();
        $picker = $this->picker();

        $response = $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        );
        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertNotEmpty($response->json('data'));

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotEmpty($ids);
        foreach ($ids as $id) {
            $this->assertDatabaseHas('picking_tasks', ['id' => $id, 'status' => 'pending']);
        }
        $this->assertSame('picking', $fulfillment->fresh()->status);
    }

    public function test_missing_placement_returns_422_and_creates_nothing(): void
    {
        // Product with stock but NO placement row → allocation fallback leaves
        // fulfillment_item.product_location_id NULL.
        $bare = Product::create([
            'name' => 'BARE', 'slug' => 'bare-'.uniqid(), 'sku' => 'SKU-BARE-1',
            'price' => 50, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 10, 'reserved_quantity' => 0,
        ]);
        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'B', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 100, 'total_price' => 100,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $bare->id, 'product_name' => 'BARE',
            'product_sku' => 'SKU-BARE-1', 'product_quantity' => 2,
            'product_price' => 50, 'product_total_price' => 100,
        ]);
        $fulfillment = app(FulfillmentService::class)->releaseForOrder($order->refresh(), $this->warehouse->id, 'ct-bare-'.uniqid());
        $this->assertNull($fulfillment->items()->first()->product_location_id);

        $response = $this->actingAs($this->picker(), 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        );
        $response->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('placement', (string) $response->getContent());
        $this->assertSame(0, PickingTask::whereHas(
            'fulfillmentItem', fn ($q) => $q->where('fulfillment_id', $fulfillment->id)
        )->count());
        $this->assertSame('pending', $fulfillment->fresh()->status);
    }

    public function test_invalid_state_returns_422(): void
    {
        $fulfillment = $this->release();
        app(OrderPickingService::class)->createTasksForFulfillment($fulfillment);
        // Drive to picked via service guards, then create-tasks must refuse.
        foreach (PickingTask::whereHas('fulfillmentItem', fn ($q) => $q->where('fulfillment_id', $fulfillment->id))->get() as $task) {
            $item = $task->fulfillmentItem;
            $item->update(['quantity_picked' => $item->quantity]);
            $task->update(['quantity_picked' => $task->quantity_to_pick, 'status' => 'picked']);
        }
        app(OrderPickingService::class)->completePicking($fulfillment->fresh());
        $this->assertSame('picked', $fulfillment->fresh()->status);

        $this->actingAs($this->picker(), 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        )->assertStatus(422);
    }

    public function test_repeated_requests_do_not_duplicate(): void
    {
        $fulfillment = $this->release();
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

    public function test_release_terminal_task_refused_without_resurrection(): void
    {
        $fulfillment = $this->release();
        $task = app(OrderPickingService::class)->createTasksForFulfillment($fulfillment)[0];
        $picker = $this->picker();

        // Complete the task to picked via claim+confirm is heavy; force picked
        // state directly to prove the release guard (service owns the rule).
        $item = $task->fulfillmentItem;
        $task->update(['quantity_picked' => $task->quantity_to_pick, 'status' => 'picked', 'claimed_by' => $picker->id]);
        $item->update(['quantity_picked' => $item->quantity]);

        $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$task->id}/release", []
        )->assertStatus(422);
        $this->assertSame('picked', $task->fresh()->status);
        $this->assertSame($picker->id, (int) $task->fresh()->claimed_by);
    }

    public function test_unauthorized_create_tasks_rejected(): void
    {
        $fulfillment = $this->release();
        $this->postJson("/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", [])->assertStatus(401);

        $viewer = $this->user(['view-fulfillment']);
        $this->actingAs($viewer, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        )->assertStatus(403);
    }
}

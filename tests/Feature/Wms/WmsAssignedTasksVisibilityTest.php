<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentBatch;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WmsAssignedTasksVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private Warehouse $warehouse;

    private User $worker;

    private User $otherWorker;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['picking-execute', 'view-fulfillment', 'manage-fulfillment', 'batch.operate', 'batch.manage'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create([
            'code' => 'WH-VIS-'.Str::random(4),
            'name' => 'Visibility Warehouse',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->worker = User::factory()->create([
            'type' => 'staff',
            'name' => 'Picker Ali',
            'warehouse_id' => $this->warehouse->id,
        ]);
        $this->worker->givePermissionTo(['picking-execute', 'view-fulfillment', 'batch.operate']);

        $this->otherWorker = User::factory()->create([
            'type' => 'staff',
            'name' => 'Picker Omar',
            'warehouse_id' => $this->warehouse->id,
        ]);
        $this->otherWorker->givePermissionTo(['picking-execute', 'view-fulfillment', 'batch.operate']);
    }

    private function createFixtures(User $assignedWorker): array
    {
        $order = Order::create([
            'tracking_number' => 'ORD-'.Str::random(8),
            'customer_id' => 1,
            'amount' => 100,
            'total' => 100,
        ]);

        $fulfillment = Fulfillment::create([
            'order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'fulfillment_number' => 'FUL-'.Str::random(8),
            'status' => 'picking',
            'assigned_to' => $assignedWorker->id,
        ]);

        $product = Product::create([
            'name' => 'Item '.Str::random(4),
            'slug' => 'item-'.Str::random(6),
            'price' => 10,
            'status' => 1,
            'in_stock' => true,
            'stock_quantity' => 10,
        ]);

        $location = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'LOC-'.Str::random(4),
            'name' => 'Bin',
            'status' => 'active',
        ]);

        $placement = ProductLocation::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'allocated_hint' => 1,
        ]);

        $orderProduct = \Marvel\Database\Models\OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'order_quantity' => 1,
            'unit_price' => 10,
            'subtotal' => 10,
        ]);

        $item = FulfillmentItem::create([
            'fulfillment_id' => $fulfillment->id,
            'order_item_id' => $orderProduct->id,
            'product_id' => $product->id,
            'product_location_id' => $placement->id,
            'quantity' => 1,
            'status' => 'pending',
        ]);

        $task = PickingTask::create([
            'fulfillment_item_id' => $item->id,
            'product_location_id' => $placement->id,
            'order_id' => $order->id,
            'order_item_id' => $orderProduct->id,
            'quantity_to_pick' => 1,
            'quantity_picked' => 0,
            'status' => 'pending',
        ]);

        return [$order, $fulfillment, $task];
    }

    public function test_mine_filter_on_picking_tasks_returns_assigned_fulfillment_tasks_before_claim(): void
    {
        // Task assigned to worker Ali on fulfillment level (claimed_by is NULL)
        [$orderA, $fulfillmentA, $taskA] = $this->createFixtures($this->worker);

        // Task assigned to other worker Omar on fulfillment level
        [$orderB, $fulfillmentB, $taskB] = $this->createFixtures($this->otherWorker);

        $res = $this->actingAs($this->worker, 'sanctum')
            ->getJson('/api/v1/admin/picking-tasks?mine=1')
            ->assertStatus(200);

        $taskIds = collect($res->json('data'))->pluck('id');
        $this->assertTrue($taskIds->contains($taskA->id), 'Worker must see tasks assigned to them via fulfillment');
        $this->assertFalse($taskIds->contains($taskB->id), 'Worker must not see tasks assigned to someone else');

        // Verify assigned_to and assigned_user in response
        $taskData = collect($res->json('data'))->firstWhere('id', $taskA->id);
        $this->assertSame($this->worker->id, $taskData['assigned_to']);
        $this->assertSame($this->worker->name, $taskData['assigned_user']['name']);
        $this->assertNull($taskData['claimed_by']);
    }

    public function test_mine_filter_on_picking_tasks_returns_batch_assigned_tasks(): void
    {
        [$order, $fulfillment, $task] = $this->createFixtures($this->otherWorker);

        // Put task into a batch assigned to worker Ali
        $batch = FulfillmentBatch::create([
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => 'BAT-'.Str::random(6),
            'status' => 'assigned',
            'assigned_to' => $this->worker->id,
            'total_items' => 1,
            'picked_items' => 0,
        ]);
        $task->update(['batch_id' => $batch->id]);

        $res = $this->actingAs($this->worker, 'sanctum')
            ->getJson('/api/v1/admin/picking-tasks?mine=1')
            ->assertStatus(200);

        $taskIds = collect($res->json('data'))->pluck('id');
        $this->assertTrue($taskIds->contains($task->id));

        $taskData = collect($res->json('data'))->firstWhere('id', $task->id);
        $this->assertSame($this->worker->id, $taskData['assigned_to']);
        $this->assertSame($this->worker->name, $taskData['assigned_user']['name']);
    }

    public function test_mine_filter_on_fulfillments_and_batches(): void
    {
        [$orderA, $fulfillmentA] = $this->createFixtures($this->worker);
        [$orderB, $fulfillmentB] = $this->createFixtures($this->otherWorker);

        $batchA = FulfillmentBatch::create([
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => 'BAT-A-'.Str::random(6),
            'status' => 'assigned',
            'assigned_to' => $this->worker->id,
            'total_items' => 1,
        ]);

        $batchB = FulfillmentBatch::create([
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => 'BAT-B-'.Str::random(6),
            'status' => 'assigned',
            'assigned_to' => $this->otherWorker->id,
            'total_items' => 1,
        ]);

        // Fulfillments mine=true
        $fRes = $this->actingAs($this->worker, 'sanctum')
            ->getJson('/api/v1/admin/fulfillments?mine=1')
            ->assertStatus(200);

        $fIds = collect($fRes->json('data'))->pluck('id');
        $this->assertTrue($fIds->contains($fulfillmentA->id));
        $this->assertFalse($fIds->contains($fulfillmentB->id));

        // Batches mine=true
        $bRes = $this->actingAs($this->worker, 'sanctum')
            ->getJson('/api/v1/admin/batches?mine=1')
            ->assertStatus(200);

        $bIds = collect($bRes->json('data'))->pluck('id');
        $this->assertTrue($bIds->contains($batchA->id));
        $this->assertFalse($bIds->contains($batchB->id));
    }
}

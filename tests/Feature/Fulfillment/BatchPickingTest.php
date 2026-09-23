<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PickingTask;
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
use Tests\TestCase;

/**
 * Phase 9 — batch picking: aggregation, fan-out traceability, per-order
 * isolation, batch-vs-individual double-pick guard.
 */
class BatchPickingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Location $location;
    private Product $product;
    private User $picker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create([
            'code' => 'WH-1', 'name' => 'Main', 'status' => 'active', 'is_default' => true,
        ]);
        $this->location = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'A-01',
            'barcode' => 'LOC-A-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-BATCH-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 100, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $this->location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'allocated_hint' => 0,
        ]);
        $this->picker = User::factory()->create(['type' => 'customer']);
    }

    private function makeReleasedOrder(int $qty, string $key): Fulfillment
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'O', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 100 * $qty, 'total_price' => 100 * $qty,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'P',
            'product_sku' => 'SKU-BATCH-1', 'product_quantity' => $qty,
            'product_price' => 100, 'product_total_price' => 100 * $qty,
        ]);

        return app(FulfillmentService::class)->releaseForOrder($order->refresh(), $this->warehouse->id, $key);
    }

    public function test_batch_aggregates_and_fans_out_per_order(): void
    {
        $f1 = $this->makeReleasedOrder(5, 'b1');
        $f2 = $this->makeReleasedOrder(3, 'b2');
        $f3 = $this->makeReleasedOrder(2, 'b3');

        $batch = app(BatchPickingService::class)
            ->createBatchFromFulfillments(collect([$f1, $f2, $f3]), $this->warehouse->id);

        // One location → tasks grouped for a single route; one task per order
        // item (5, 3, 2) so picked units stay attributable without fan-out math.
        $this->assertEquals(3, $batch->pickingTasks()->count());
        $this->assertEquals(
            [2, 3, 5],
            $batch->pickingTasks()->orderBy('quantity_to_pick')->pluck('quantity_to_pick')
                ->map(fn ($q) => (int) $q)->all()
        );
        // Fan-out denorm present on every task.
        $this->assertTrue($batch->pickingTasks()->whereNull('order_id')->doesntExist());

        // Claim + confirm each task through the shared engine.
        $engine = app(PickingExecutionService::class);
        $seq = 0;
        foreach ($batch->pickingTasks()->orderBy('id')->get() as $task) {
            $engine->claim($task, $this->picker->id);
            $engine->confirm($task, [
                'location' => 'LOC-A-01', 'product' => 'SKU-BATCH-1',
                'quantity' => (float) $task->quantity_to_pick, 'op_seq' => ++$seq,
            ], $this->picker->id);
        }

        // Per-order tallies exact — no leakage between orders.
        $this->assertEquals(5, (float) $f1->items()->first()->refresh()->quantity_picked);
        $this->assertEquals(3, (float) $f2->items()->first()->refresh()->quantity_picked);
        $this->assertEquals(2, (float) $f3->items()->first()->refresh()->quantity_picked);

        // Central counters untouched by picking.
        $this->assertEquals(100, (int) $this->product->refresh()->stock_quantity);
    }

    public function test_batch_task_blocks_duplicate_individual_task(): void
    {
        $fulfillment = $this->makeReleasedOrder(4, 'b4');
        app(BatchPickingService::class)
            ->createBatchFromFulfillments(collect([$fulfillment]), $this->warehouse->id);

        // Order picking must reuse the open batch task, not create a second one.
        $tasks = app(OrderPickingService::class)->createTasksForFulfillment($fulfillment);

        $this->assertCount(1, $tasks);
        $this->assertNotNull($tasks[0]->batch_id);
        $this->assertEquals(
            1,
            PickingTask::where('fulfillment_item_id', $fulfillment->items()->first()->id)
                ->whereIn('status', ['pending', 'assigned', 'picking'])
                ->count()
        );
    }

    public function test_batch_progress_refresh_after_engine_confirms(): void
    {
        $f1 = $this->makeReleasedOrder(2, 'b5');
        $f2 = $this->makeReleasedOrder(2, 'b6');
        $service = app(BatchPickingService::class);
        $batch = $service->createBatchFromFulfillments(collect([$f1, $f2]), $this->warehouse->id);

        $engine = app(PickingExecutionService::class);
        foreach ($batch->pickingTasks()->get() as $i => $task) {
            $engine->claim($task, $this->picker->id);
            $engine->confirm($task, [
                'location' => 'LOC-A-01', 'product' => 'SKU-BATCH-1',
                'quantity' => (float) $task->quantity_to_pick, 'op_seq' => $i + 1,
            ], $this->picker->id);
        }

        $refreshed = $service->refreshBatchProgress($batch);

        $this->assertEquals('completed', $refreshed->status);
        // Fulfillments advanced to picked via the owner.
        foreach ([$f1, $f2] as $f) {
            $this->assertEquals('picked', $f->refresh()->status);
        }
    }
}

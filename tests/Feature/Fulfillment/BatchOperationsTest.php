<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentBatch;
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
 * Phase 5 — batch/wave operations (P5-1…P5-5): locked scope revalidation,
 * empty-set refusal, skipped-task retry, centralized completion, parent
 * batch cancellation with fulfillment cancellation.
 */
class BatchOperationsTest extends TestCase
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
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-P5-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 100, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $this->location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'allocated_hint' => 0,
        ]);
        $this->picker = User::factory()->create(['type' => 'customer']);
    }

    private function releasedFulfillment(int $qty, string $key, string $orderStatus = 'pending'): Fulfillment
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'O', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => $orderStatus,
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
            'product_sku' => 'SKU-P5-1', 'product_quantity' => $qty,
            'product_price' => 100, 'product_total_price' => 100 * $qty,
        ]);

        return app(FulfillmentService::class)->releaseForOrder($order->refresh(), $this->warehouse->id, $key);
    }

    private function batches(): BatchPickingService
    {
        return app(BatchPickingService::class);
    }

    private function engine(): PickingExecutionService
    {
        return app(PickingExecutionService::class);
    }

    private function scan(float $qty, int $seq = 1): array
    {
        return ['location' => 'LOC-A-01', 'product' => 'SKU-P5-1', 'quantity' => $qty, 'op_seq' => $seq];
    }

    // ---------------- P5-5: locked scope revalidation ----------------

    public function test_p55_same_flow_and_stage_succeeds(): void
    {
        $f1 = $this->releasedFulfillment(2, 'p55-1');
        $f2 = $this->releasedFulfillment(3, 'p55-2');

        $batch = $this->batches()->createBatchFromFulfillments(collect([$f1, $f2]), $this->warehouse->id);

        $this->assertEquals(2, $batch->pickingTasks()->count());
        $this->assertEquals('picking', $f1->refresh()->status);
        $this->assertEquals('picking', $f2->refresh()->status);
    }

    public function test_p55_flow_mismatch_rejected_without_side_effects(): void
    {
        $f1 = $this->releasedFulfillment(2, 'p55-3');
        $f2 = $this->releasedFulfillment(2, 'p55-4');
        // Legitimate different Order Flow via the canonical flow writer.
        app(\App\Services\OrderFlow\OrderFlowService::class)
            ->assignFlowToOrder($f2->order()->first()->refresh(), 'international');

        try {
            $this->batches()->createBatchFromFulfillments(collect([$f1, $f2]), $this->warehouse->id);
            $this->fail('cross-flow batch must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('flow', strtolower($e->getMessage()));
        }
        $this->assertEquals(0, FulfillmentBatch::count());
        $this->assertEquals(0, PickingTask::count());
        $this->assertEquals('pending', $f1->refresh()->status);
        $this->assertEquals('pending', $f2->refresh()->status);
    }

    public function test_p55_stage_mismatch_rejected_without_side_effects(): void
    {
        $f1 = $this->releasedFulfillment(2, 'p55-5');
        $f2 = $this->releasedFulfillment(2, 'p55-6');
        $f2->order()->first()->update(['status' => 'processing']);

        try {
            $this->batches()->createBatchFromFulfillments(collect([$f1, $f2]), $this->warehouse->id);
            $this->fail('cross-stage batch must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('stage', strtolower($e->getMessage()));
        }
        $this->assertEquals(0, FulfillmentBatch::count());
        $this->assertEquals(0, PickingTask::count());
    }

    public function test_p55_locked_recheck_catches_stale_preselection(): void
    {
        $f1 = $this->releasedFulfillment(2, 'p55-7');
        $f2 = $this->releasedFulfillment(2, 'p55-8');
        // Pre-check reads the relations below (stale snapshots); the order
        // stage then drifts before creation (simulated race, test setup only).
        $stale = collect([$f1->load('order'), $f2->load('order')]);
        $f2->order()->first()->update(['status' => 'processing']);

        try {
            $this->batches()->createBatchFromFulfillments($stale, $this->warehouse->id);
            $this->fail('stale pre-selection must fail the locked recheck');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('stage', strtolower($e->getMessage()));
        }
        // No partial state: no batch, no tasks, no fulfillment moved.
        $this->assertEquals(0, FulfillmentBatch::count());
        $this->assertEquals(0, PickingTask::count());
        $this->assertEquals('pending', $f1->refresh()->status);
        $this->assertEquals('pending', $f2->refresh()->status);
    }

    public function test_p55_warehouse_mismatch_rejected(): void
    {
        $other = Warehouse::create([
            'code' => 'WH-2', 'name' => 'Other', 'status' => 'active', 'is_default' => false,
        ]);
        $f1 = $this->releasedFulfillment(2, 'p55-9');
        $f2 = $this->releasedFulfillment(2, 'p55-10');
        $f2->update(['warehouse_id' => $other->id]);

        try {
            $this->batches()->createBatchFromFulfillments(collect([$f1, $f2]), $this->warehouse->id);
            $this->fail('cross-warehouse batch must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('warehouse', strtolower($e->getMessage()));
        }
        $this->assertEquals(0, FulfillmentBatch::count());
    }

    // ---------------- P5-4: empty item-set ----------------

    public function test_p54_fully_picked_only_input_refused_without_side_effects(): void
    {
        $fulfillment = $this->releasedFulfillment(4, 'p54-1');
        $fulfillment->items()->firstOrFail()->update(['quantity_picked' => 4]);

        try {
            $this->batches()->createBatchFromFulfillments(collect([$fulfillment]), $this->warehouse->id);
            $this->fail('taskless batch must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('zero pickable items', $e->getMessage());
        }
        $this->assertEquals(0, FulfillmentBatch::count());
        $this->assertEquals(0, PickingTask::count());
        $this->assertEquals('pending', $fulfillment->refresh()->status);
    }

    public function test_p54_partial_items_still_create_batch(): void
    {
        $fulfillment = $this->releasedFulfillment(5, 'p54-2');
        $fulfillment->items()->firstOrFail()->update(['quantity_picked' => 2]);

        $batch = $this->batches()->createBatchFromFulfillments(collect([$fulfillment]), $this->warehouse->id);

        $this->assertEquals(1, $batch->pickingTasks()->count());
        $this->assertEquals(3, (float) $batch->pickingTasks()->first()->quantity_to_pick);
    }

    // ---------------- P5-1: retry skipped ----------------

    public function test_p51_skip_retry_completes_batch(): void
    {
        $fulfillment = $this->releasedFulfillment(4, 'p51-1');
        $batch = $this->batches()->createBatchFromFulfillments(collect([$fulfillment]), $this->warehouse->id);
        [$task] = $batch->pickingTasks()->get()->all();
        $this->engine()->claim($task, $this->picker->id);
        $this->engine()->confirm($task, $this->scan(1, 1), $this->picker->id);
        $this->batches()->skipTask($task, 'damaged remainder');
        $this->assertEquals('skipped', $task->refresh()->status);

        $orderStatusBefore = $fulfillment->order()->first()->status;
        $stockBefore = (int) $this->product->refresh()->stock_quantity;

        $retry = $this->batches()->retrySkippedTasks($batch, 'restocked shelf');
        $this->assertCount(1, $retry);
        $this->assertEquals($batch->id, (int) $retry[0]->batch_id);
        $this->assertEquals(3, (float) $retry[0]->quantity_to_pick);
        $this->assertEquals('pending', $retry[0]->status);
        $this->assertEquals('skipped', $task->refresh()->status); // old row terminal
        // 1:1 swap: one dead row superseded by one live row, so total_items
        // (the live-task count completion relies on) is unchanged.
        $this->assertEquals(1, (int) $batch->refresh()->total_items);

        // Retry executes through the engine and completes the batch.
        $this->engine()->claim($retry[0], $this->picker->id);
        $this->engine()->confirm($retry[0], $this->scan(3, 2), $this->picker->id);
        $refreshed = $this->batches()->refreshBatchProgress($batch);
        $this->assertEquals('completed', $refreshed->status);
        $this->assertEquals('picked', $fulfillment->refresh()->status);

        // Authority intact: no order auto-advance, no stock movement.
        $this->assertEquals($orderStatusBefore, $fulfillment->order()->first()->refresh()->status);
        $this->assertEquals($stockBefore, (int) $this->product->refresh()->stock_quantity);
    }

    public function test_p51_duplicate_retry_cannot_duplicate_open_task(): void
    {
        $fulfillment = $this->releasedFulfillment(4, 'p51-2');
        $batch = $this->batches()->createBatchFromFulfillments(collect([$fulfillment]), $this->warehouse->id);
        [$task] = $batch->pickingTasks()->get()->all();
        $this->batches()->skipTask($task, 'no stock');

        $first = $this->batches()->retrySkippedTasks($batch, 'again');
        $this->assertCount(1, $first);
        $item = $fulfillment->items()->firstOrFail();

        try {
            $this->batches()->retrySkippedTasks($batch, 'again');
            $this->fail('second retry must refuse the open replacement');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already has open picking task', $e->getMessage());
        }
        // No skipped rows remain retryable; exactly one open task exists.
        $this->assertEquals(
            1,
            PickingTask::where('fulfillment_item_id', $item->id)
                ->whereIn('status', ['pending', 'assigned', 'picking'])->count()
        );
    }

    public function test_p51_zero_remaining_creates_nothing_and_rejects_terminals(): void
    {
        $fulfillment = $this->releasedFulfillment(4, 'p51-3');
        $batch = $this->batches()->createBatchFromFulfillments(collect([$fulfillment]), $this->warehouse->id);
        [$task] = $batch->pickingTasks()->get()->all();
        $this->batches()->skipTask($task, 'oops');
        // Item completed through another path meanwhile: nothing retryable.
        $fulfillment->items()->firstOrFail()->update(['quantity_picked' => 4]);

        $this->assertEquals([], $this->batches()->retrySkippedTasks($batch, 'late'));
        $this->assertEquals(1, (int) $batch->refresh()->total_items);

        foreach (['', '   '] as $bad) {
            try {
                $this->batches()->retrySkippedTasks($batch, $bad);
                $this->fail('empty retry reason must be rejected');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('reason', strtolower($e->getMessage()));
            }
        }

        $this->batches()->cancelBatch($batch, 'done');
        try {
            $this->batches()->retrySkippedTasks($batch->refresh(), 'late');
            $this->fail('retry on cancelled batch must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }
    }

    public function test_p51_picked_tasks_untouched_by_retry(): void
    {
        $fulfillment = $this->releasedFulfillment(2, 'p51-4');
        $batch = $this->batches()->createBatchFromFulfillments(collect([$fulfillment]), $this->warehouse->id);
        [$task] = $batch->pickingTasks()->get()->all();
        $this->engine()->claim($task, $this->picker->id);
        $this->engine()->confirm($task, $this->scan(2, 1), $this->picker->id);

        $this->assertEquals([], $this->batches()->retrySkippedTasks($batch, 'nothing skipped'));
        $this->assertEquals('picked', $task->refresh()->status);
    }

    // ---------------- P5-2: centralized completion ----------------

    public function test_p52_completion_idempotent_across_paths(): void
    {
        $fulfillment = $this->releasedFulfillment(3, 'p52-1');
        $batch = $this->batches()->createBatchFromFulfillments(collect([$fulfillment]), $this->warehouse->id);
        [$task] = $batch->pickingTasks()->get()->all();
        $this->engine()->claim($task, $this->picker->id);
        $this->engine()->confirm($task, $this->scan(3, 1), $this->picker->id);

        $completedAt = $this->batches()->refreshBatchProgress($batch)->completed_at;
        $this->assertNotNull($completedAt);
        $again = $this->batches()->refreshBatchProgress($batch->refresh());
        $this->assertEquals('completed', $again->status);
        $this->assertEquals($completedAt->toIso8601String(), $again->completed_at->toIso8601String());
        $this->assertEquals('picked', $fulfillment->refresh()->status);
        // Single fulfillment transition: still picked, no duplicate side effect.
        $this->assertEquals(1, $batch->refresh()->pickingTasks()->where('status', 'picked')->count());
    }

    public function test_p52_partial_batch_stays_incomplete(): void
    {
        $fulfillment = $this->releasedFulfillment(4, 'p52-2');
        $batch = $this->batches()->createBatchFromFulfillments(collect([$fulfillment]), $this->warehouse->id);
        [$task] = $batch->pickingTasks()->get()->all();
        $this->engine()->claim($task, $this->picker->id);
        $this->engine()->confirm($task, $this->scan(1, 1), $this->picker->id);

        $refreshed = $this->batches()->refreshBatchProgress($batch);
        $this->assertNotEquals('completed', $refreshed->status);
        $this->assertNull($refreshed->completed_at);
        $this->assertEquals('picking', $fulfillment->refresh()->status);
    }

    // ---------------- P5-3: cancel parents ----------------

    public function test_p53_fulfillment_cancel_cancels_parent_batch(): void
    {
        $fulfillment = $this->releasedFulfillment(4, 'p53-1');
        $batch = $this->batches()->createBatchFromFulfillments(collect([$fulfillment]), $this->warehouse->id);
        [$task] = $batch->pickingTasks()->get()->all();
        $this->engine()->claim($task, $this->picker->id);
        // One unit picked first: picked progress must survive cancellation.
        $this->engine()->confirm($task, $this->scan(1, 1), $this->picker->id);

        $orderStatusBefore = $fulfillment->order()->first()->status;
        $stockBefore = (int) $this->product->refresh()->stock_quantity;

        app(FulfillmentService::class)->cancelFulfillment($fulfillment, 'customer changed mind');

        $this->assertEquals('cancelled', $fulfillment->refresh()->status);
        $this->assertEquals('cancelled', $batch->refresh()->status);
        $this->assertEquals('skipped', $task->refresh()->status);
        $this->assertNull($task->refresh()->claimed_by);
        $this->assertEquals(1, (float) $fulfillment->items()->firstOrFail()->refresh()->quantity_picked);
        // Batch/fulfillment cancellation never moves orders or stock.
        $this->assertEquals($orderStatusBefore, $fulfillment->order()->first()->refresh()->status);
        $this->assertEquals($stockBefore, (int) $this->product->refresh()->stock_quantity);

        // Repeated cancellation is safe (idempotent, no resurrection).
        app(FulfillmentService::class)->cancelFulfillment($fulfillment->refresh(), 'customer changed mind');
        $this->assertEquals('cancelled', $batch->refresh()->status);
        $this->assertEquals('skipped', $task->refresh()->status);
    }

    public function test_p53_picked_batch_task_survives_parent_cancel(): void
    {
        $fulfillment = $this->releasedFulfillment(2, 'p53-2');
        $batch = $this->batches()->createBatchFromFulfillments(collect([$fulfillment]), $this->warehouse->id);
        [$task] = $batch->pickingTasks()->get()->all();
        $this->engine()->claim($task, $this->picker->id);
        $this->engine()->confirm($task, $this->scan(2, 1), $this->picker->id);
        $this->assertEquals('picked', $task->refresh()->status);

        app(FulfillmentService::class)->cancelFulfillment($fulfillment, 'fraud review');

        $this->assertEquals('picked', $task->refresh()->status);
        $this->assertEquals(2, (float) $task->refresh()->quantity_picked);
    }
}

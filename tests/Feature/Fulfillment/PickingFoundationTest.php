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
use App\Services\Fulfillment\PackingService;
use App\Services\Fulfillment\PickingExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 4 — picking foundation (P4-1…P4-9): advancement, creation
 * correctness, skip/cancel/sweep/record guards, execution-time placement
 * revalidation, terminal override protection, creation concurrency.
 */
class PickingFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Location $location;
    private ProductLocation $placement;
    private Product $product;
    private User $picker;
    private User $other;

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
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-P4-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        $this->placement = ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $this->location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);
        $this->picker = User::factory()->create(['type' => 'customer']);
        $this->other = User::factory()->create(['type' => 'customer']);
    }

    private function releasedFulfillment(int $qty, string $key): Fulfillment
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
            'product_sku' => 'SKU-P4-1', 'product_quantity' => $qty,
            'product_price' => 100, 'product_total_price' => 100 * $qty,
        ]);

        return app(FulfillmentService::class)->releaseForOrder($order->refresh(), $this->warehouse->id, $key);
    }

    private function engine(): PickingExecutionService
    {
        return app(PickingExecutionService::class);
    }

    private function orderPicking(): OrderPickingService
    {
        return app(OrderPickingService::class);
    }

    private function scan(float $qty, int $seq = 1): array
    {
        return ['location' => 'LOC-A-01', 'product' => 'SKU-P4-1', 'quantity' => $qty, 'op_seq' => $seq];
    }

    // ---------------- P4-1: order-flow advancement ----------------

    public function test_p41_task_creation_moves_pending_to_picking(): void
    {
        $fulfillment = $this->releasedFulfillment(4, 'p41-1');
        $this->assertEquals('pending', $fulfillment->status);

        $tasks = $this->orderPicking()->createTasksForFulfillment($fulfillment);

        $this->assertCount(1, $tasks);
        $this->assertEquals('picking', $fulfillment->refresh()->status);
    }

    public function test_p41_partial_pick_does_not_complete(): void
    {
        $fulfillment = $this->releasedFulfillment(4, 'p41-2');
        [$task] = $this->orderPicking()->createTasksForFulfillment($fulfillment);
        $this->engine()->claim($task, $this->picker->id);
        $this->engine()->confirm($task, $this->scan(1, 1), $this->picker->id);

        try {
            $this->orderPicking()->completePicking($fulfillment);
            $this->fail('partial work must refuse explicit completion');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('incomplete', $e->getMessage());
        }
        $this->assertEquals('picking', $fulfillment->refresh()->status);
    }

    public function test_p41_full_tasks_require_explicit_completion(): void
    {
        $fulfillment = $this->releasedFulfillment(4, 'p41-3');
        [$task] = $this->orderPicking()->createTasksForFulfillment($fulfillment);
        $this->engine()->claim($task, $this->picker->id);
        $done = $this->engine()->confirm($task, $this->scan(4, 1), $this->picker->id);
        $this->assertEquals('picked', $done->status);

        // Task-level picked is progress only: fulfillment waits for explicit op.
        $this->assertEquals('picking', $fulfillment->refresh()->status);

        $completed = $this->orderPicking()->completePicking($fulfillment);
        $this->assertEquals('picked', $completed->status);
        $this->assertEquals('picked', $fulfillment->refresh()->status);
    }

    public function test_p41_repeat_completion_is_idempotent(): void
    {
        $fulfillment = $this->releasedFulfillment(2, 'p41-4');
        [$task] = $this->orderPicking()->createTasksForFulfillment($fulfillment);
        $this->engine()->claim($task, $this->picker->id);
        $this->engine()->confirm($task, $this->scan(2, 1), $this->picker->id);

        $this->orderPicking()->completePicking($fulfillment);
        $again = $this->orderPicking()->completePicking($fulfillment);
        $this->assertEquals('picked', $again->status);
    }

    public function test_p41_packing_gate_reachable_and_order_untouched(): void
    {
        $fulfillment = $this->releasedFulfillment(2, 'p41-5');
        $orderStatusBefore = $fulfillment->order()->first()->status;
        [$task] = $this->orderPicking()->createTasksForFulfillment($fulfillment);
        $this->engine()->claim($task, $this->picker->id);
        $this->engine()->confirm($task, $this->scan(2, 1), $this->picker->id);
        $this->orderPicking()->completePicking($fulfillment);

        $packingTask = app(PackingService::class)->createPackingTaskFromFulfillment($fulfillment->refresh());
        $this->assertNotNull($packingTask->id);
        $this->assertEquals('packing', $fulfillment->refresh()->status);
        // Order Flow untouched by the whole picking pipeline.
        $this->assertEquals($orderStatusBefore, $fulfillment->order()->first()->refresh()->status);
    }

    // ---------------- P4-2: batch creation correctness ----------------

    public function test_p42_partially_picked_item_creates_remaining_only(): void
    {
        $fulfillment = $this->releasedFulfillment(5, 'p42-1');
        $item = $fulfillment->items()->firstOrFail();
        $item->update(['quantity_picked' => 2]); // simulated prior progress

        $batch = app(BatchPickingService::class)
            ->createBatchFromFulfillments(collect([$fulfillment]), $this->warehouse->id);

        $this->assertEquals(1, $batch->pickingTasks()->count());
        $this->assertEquals(3, (float) $batch->pickingTasks()->first()->quantity_to_pick);
    }

    public function test_p42_fully_picked_item_creates_no_task(): void
    {
        // SUPERSEDED by P5-4: a fully-picked-only item set is now refused
        // loudly (no taskless batch) instead of creating an empty batch.
        $fulfillment = $this->releasedFulfillment(5, 'p42-2');
        $item = $fulfillment->items()->firstOrFail();
        $item->update(['quantity_picked' => 5]); // simulated prior progress

        try {
            app(BatchPickingService::class)
                ->createBatchFromFulfillments(collect([$fulfillment]), $this->warehouse->id);
            $this->fail('taskless batch must be refused (P5-4)');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('zero pickable items', $e->getMessage());
        }
        $this->assertEquals(0, PickingTask::count());
        $this->assertEquals('pending', $fulfillment->refresh()->status);
    }

    public function test_p42_existing_order_task_blocks_batch_creation(): void
    {
        $fulfillment = $this->releasedFulfillment(4, 'p42-3');
        $item = $fulfillment->items()->firstOrFail();
        PickingTask::create([
            'batch_id' => null, 'fulfillment_item_id' => $item->id,
            'product_location_id' => $item->product_location_id,
            'order_id' => $fulfillment->order_id, 'order_item_id' => $item->order_item_id,
            'quantity_to_pick' => 4, 'quantity_picked' => 0,
            'status' => 'pending', 'sequence' => 1,
        ]);

        try {
            app(BatchPickingService::class)->createBatchFromFulfillments(collect([$fulfillment]), $this->warehouse->id);
            $this->fail('open order-flow task must block duplicate batch task');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already has open picking task', $e->getMessage());
        }
        $this->assertEquals(1, PickingTask::where('fulfillment_item_id', $item->id)->count());
    }

    public function test_p42_second_batch_creation_cannot_duplicate(): void
    {
        $fulfillment = $this->releasedFulfillment(4, 'p42-4');
        $service = app(BatchPickingService::class);
        $service->createBatchFromFulfillments(collect([$fulfillment]), $this->warehouse->id);

        try {
            $service->createBatchFromFulfillments(collect([$fulfillment->refresh()]), $this->warehouse->id);
            $this->fail('second batch over the same fulfillment must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('!= pending', $e->getMessage());
        }
        $item = $fulfillment->items()->firstOrFail();
        $this->assertEquals(1, PickingTask::where('fulfillment_item_id', $item->id)->count());
    }

    // ---------------- P4-3: skipTask ----------------

    public function test_p43_skip_open_states_and_reject_terminal_and_empty_reason(): void
    {
        $service = app(BatchPickingService::class);
        $fulfillment = $this->releasedFulfillment(6, 'p43-1');
        $item = $fulfillment->items()->firstOrFail();
        $mk = fn (string $status, float $picked = 0) => PickingTask::create([
            'batch_id' => null, 'fulfillment_item_id' => $item->id,
            'product_location_id' => $item->product_location_id,
            'order_id' => $fulfillment->order_id, 'order_item_id' => $item->order_item_id,
            'quantity_to_pick' => 6, 'quantity_picked' => $picked,
            'status' => $status, 'sequence' => 1,
            'claimed_by' => in_array($status, ['assigned', 'picking']) ? $this->picker->id : null,
        ]);

        $pickingSkippedId = null;
        foreach (['pending', 'assigned', 'picking'] as $state) {
            $skipped = $service->skipTask($mk($state, $state === 'picking' ? 2 : 0), ' damaged ');
            $this->assertEquals('skipped', $skipped->status);
            $this->assertEquals('damaged', $skipped->notes);
            if ($state === 'picking') {
                $pickingSkippedId = $skipped->id;
            }
        }
        // Progress preserved on the picking skip.
        $this->assertEquals(2, (float) PickingTask::whereKey($pickingSkippedId)->first()->quantity_picked);

        foreach (['picked', 'skipped'] as $terminal) {
            try {
                $service->skipTask($mk($terminal, $terminal === 'picked' ? 6 : 0), 'reason');
                $this->fail("skip from {$terminal} must be rejected");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Cannot skip', $e->getMessage());
            }
        }

        foreach (['', '   '] as $bad) {
            try {
                $service->skipTask($mk('pending'), $bad);
                $this->fail('empty reason must be rejected');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('reason', strtolower($e->getMessage()));
            }
        }
    }

    public function test_p43_retry_task_creatable_after_skip(): void
    {
        $fulfillment = $this->releasedFulfillment(4, 'p43-2');
        [$task] = $this->orderPicking()->createTasksForFulfillment($fulfillment);
        app(BatchPickingService::class)->skipTask($task, 'out of stock');

        $retry = $this->orderPicking()->createTasksForFulfillment($fulfillment);
        $this->assertCount(1, $retry);
        $this->assertNotEquals($task->id, $retry[0]->id);
        $this->assertEquals('pending', $retry[0]->status);
    }

    // ---------------- P4-4: cancelBatch ----------------

    public function test_p44_cancel_releases_assigned_and_keeps_picked(): void
    {
        $service = app(BatchPickingService::class);
        $fulfillment = $this->releasedFulfillment(4, 'p44-1');
        // Second order item → second fulfillment item → second batch task.
        $order = $fulfillment->order()->first();
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'P',
            'product_sku' => 'SKU-P4-1', 'product_quantity' => 1,
            'product_price' => 100, 'product_total_price' => 100,
        ]);
        $second = $order->orderItems()->orderBy('id', 'desc')->first();
        $fulfillment->items()->create([
            'order_item_id' => $second->id, 'product_id' => $this->product->id,
            'product_location_id' => $this->placement->id,
            'quantity' => 1, 'quantity_picked' => 0, 'status' => 'pending',
        ]);

        $batch = $service->createBatchFromFulfillments(collect([$fulfillment->refresh()]), $this->warehouse->id);
        $this->assertEquals(2, $batch->pickingTasks()->count());
        [$assigned, $picked] = $batch->pickingTasks()->orderBy('id')->get()->all();

        $this->engine()->claim($assigned, $this->picker->id);
        $this->engine()->claim($picked, $this->picker->id);
        $this->engine()->confirm($picked, $this->scan((float) $picked->quantity_to_pick, 1), $this->picker->id);
        $this->assertEquals('picked', $picked->refresh()->status);

        $service->cancelBatch($batch, 'shift end');

        $this->assertEquals('skipped', $assigned->refresh()->status);
        $this->assertNull($assigned->refresh()->claimed_by);
        $this->assertEquals('picked', $picked->refresh()->status);
    }

    // ---------------- P4-5: sweep race ----------------

    public function test_p45_sweep_releases_normal_expired_claim(): void
    {
        $fulfillment = $this->releasedFulfillment(2, 'p45-1');
        [$task] = $this->orderPicking()->createTasksForFulfillment($fulfillment);
        $this->engine()->claim($task, $this->picker->id, 15);

        $this->travel(16)->minutes();
        $released = $this->engine()->sweepExpiredClaims();

        $this->assertEquals(1, $released);
        $this->assertEquals('pending', $task->refresh()->status);
        $this->assertNull($task->refresh()->claimed_by);
    }

    public function test_p45_sweep_never_regresses_completed_task(): void
    {
        $fulfillment = $this->releasedFulfillment(2, 'p45-2');
        [$task] = $this->orderPicking()->createTasksForFulfillment($fulfillment);
        $this->engine()->claim($task, $this->picker->id, 15);

        $this->travel(16)->minutes();
        // Task completes after the lease expired (the race window).
        $done = $this->engine()->confirm($task, $this->scan(2, 1), $this->picker->id);
        $this->assertEquals('picked', $done->status);

        $released = $this->engine()->sweepExpiredClaims();

        $this->assertEquals(0, $released);
        $this->assertEquals('picked', $task->refresh()->status);
        $this->assertEquals(2, (float) $task->refresh()->quantity_picked);
    }

    public function test_p45_sweep_never_clears_refreshed_lease(): void
    {
        $fulfillment = $this->releasedFulfillment(2, 'p45-3');
        [$task] = $this->orderPicking()->createTasksForFulfillment($fulfillment);
        $this->engine()->claim($task, $this->picker->id, 15);

        $this->travel(16)->minutes();
        // Same worker re-claims after expiry (lease refresh / steal recovery).
        $refreshed = $this->engine()->claim($task, $this->picker->id, 15);
        $this->assertEquals('assigned', $refreshed->status);

        $released = $this->engine()->sweepExpiredClaims();

        $this->assertEquals(0, $released);
        $this->assertEquals('assigned', $task->refresh()->status);
        $this->assertEquals($this->picker->id, (int) $task->refresh()->claimed_by);
    }

    // ---------------- P4-6: recordPick parity ----------------

    public function test_p46_record_pick_guards(): void
    {
        $service = app(BatchPickingService::class);
        $fulfillment = $this->releasedFulfillment(4, 'p46-1');
        $item = $fulfillment->items()->firstOrFail();
        $mk = fn (string $status, ?int $claimedBy = null) => PickingTask::create([
            'batch_id' => null, 'fulfillment_item_id' => $item->id,
            'product_location_id' => $item->product_location_id,
            'order_id' => $fulfillment->order_id, 'order_item_id' => $item->order_item_id,
            'quantity_to_pick' => 4, 'quantity_picked' => 0,
            'status' => $status, 'sequence' => 1, 'claimed_by' => $claimedBy,
        ]);

        // Valid: assigned + claimant, then picking + claimant.
        $ok = $service->recordPick($mk('assigned', $this->picker->id), 1, null, $this->picker->id);
        $this->assertEquals('picking', $ok->status);
        $ok = $service->recordPick($ok, 3, null, $this->picker->id);
        $this->assertEquals('picked', $ok->status);

        // Terminal states rejected.
        foreach (['pending', 'skipped', 'picked'] as $bad) {
            try {
                $t = $mk($bad, $bad === 'picked' ? $this->picker->id : null);
                if ($bad === 'picked') {
                    $t->update(['quantity_picked' => 4]);
                }
                $service->recordPick($t, 1, null, $this->picker->id);
                $this->fail("recordPick on {$bad} must be rejected");
            } catch (\Exception $e) {
                $this->assertStringContainsString('Cannot record pick', $e->getMessage());
            }
        }

        // Wrong claimant (and missing claimant) rejected; override allowed.
        try {
            $service->recordPick($mk('assigned', $this->picker->id), 1, null, $this->other->id);
            $this->fail('wrong claimant must be rejected');
        } catch (\Exception $e) {
            $this->assertStringContainsString('another worker', $e->getMessage());
        }
        $overridden = $service->recordPick($mk('assigned', $this->picker->id), 1, null, $this->other->id, true);
        $this->assertEquals(1, (float) $overridden->quantity_picked);

        // Over-pick rejected, partial stays correct.
        $partial = $mk('assigned', $this->picker->id);
        try {
            $service->recordPick($partial, 99, null, $this->picker->id);
            $this->fail('over-pick must be rejected');
        } catch (\Exception $e) {
            $this->assertStringContainsString('exceeds remaining', $e->getMessage());
        }
        $this->assertEquals(0, (float) $partial->refresh()->quantity_picked);
    }

    // ---------------- P4-7: confirm-time revalidation ----------------

    public function test_p47_deactivated_location_rejects_confirm_without_mutation(): void
    {
        $fulfillment = $this->releasedFulfillment(4, 'p47-1');
        [$task] = $this->orderPicking()->createTasksForFulfillment($fulfillment);
        $this->engine()->claim($task, $this->picker->id);
        $stockBefore = (int) $this->product->refresh()->stock_quantity;

        $this->location->update(['status' => 'inactive']);

        try {
            $this->engine()->confirm($task, $this->scan(4, 1), $this->picker->id);
            $this->fail('pick from inactive location must be rejected');
        } catch (\App\Exceptions\PickingValidationException $e) {
            $this->assertEquals('stale_placement', $e->context['reason']);
        }
        $this->assertEquals(0, (float) $task->refresh()->quantity_picked);
        $this->assertEquals($stockBefore, (int) $this->product->refresh()->stock_quantity);
    }

    public function test_p47_placement_drift_rejects_and_reallocation_recovers(): void
    {
        $fulfillment = $this->releasedFulfillment(3, 'p47-2');
        [$task] = $this->orderPicking()->createTasksForFulfillment($fulfillment);
        $this->engine()->claim($task, $this->picker->id);

        // Simulate drift: placement warehouse no longer matches.
        $otherWarehouse = Warehouse::create([
            'code' => 'WH-9', 'name' => 'Other', 'status' => 'active', 'is_default' => false,
        ]);
        $this->placement->update(['warehouse_id' => $otherWarehouse->id]);

        try {
            $this->engine()->confirm($task, $this->scan(3, 1), $this->picker->id);
            $this->fail('drifted placement must be rejected');
        } catch (\App\Exceptions\PickingValidationException $e) {
            $this->assertEquals('stale_placement', $e->context['reason']);
        }

        // Operator reallocates to a valid same-warehouse placement → works.
        $freshLocation = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'A-02',
            'barcode' => 'LOC-A-02', 'name' => 'Bin 2', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $freshPlacement = ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $freshLocation->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);
        $this->engine()->reallocateTask($task, $freshPlacement->id);
        $done = $this->engine()->confirm($task, [
            'location' => 'LOC-A-02', 'product' => 'SKU-P4-1', 'quantity' => 3, 'op_seq' => 2,
        ], $this->picker->id);
        $this->assertEquals('picked', $done->status);
    }

    // ---------------- P4-8: terminal override ----------------

    public function test_p48_override_never_reopens_terminal_but_keeps_open_override(): void
    {
        $fulfillment = $this->releasedFulfillment(2, 'p48-1');
        [$task] = $this->orderPicking()->createTasksForFulfillment($fulfillment);
        $this->engine()->claim($task, $this->picker->id);
        $this->engine()->confirm($task, $this->scan(2, 1), $this->picker->id);
        $this->assertEquals('picked', $task->refresh()->status);

        foreach ([$this->picker->id, $this->other->id] as $actor) {
            try {
                $this->engine()->claim($task, $actor, 15, true);
                $this->fail('override on picked must be rejected');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('terminal', $e->getMessage());
            }
        }

        $skipped = PickingTask::create([
            'batch_id' => null, 'fulfillment_item_id' => $task->fulfillment_item_id,
            'product_location_id' => $task->product_location_id,
            'order_id' => $fulfillment->order_id, 'order_item_id' => $task->order_item_id,
            'quantity_to_pick' => 2, 'quantity_picked' => 0,
            'status' => 'skipped', 'sequence' => 9,
        ]);
        try {
            $this->engine()->claim($skipped, $this->other->id, 15, true);
            $this->fail('override on skipped must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('terminal', $e->getMessage());
        }

        // Open-task override by another worker still works (existing contract).
        $fulfillment2 = $this->releasedFulfillment(2, 'p48-2');
        [$open] = $this->orderPicking()->createTasksForFulfillment($fulfillment2);
        $this->engine()->claim($open, $this->picker->id);
        $taken = $this->engine()->claim($open, $this->other->id, 15, true);
        $this->assertEquals($this->other->id, (int) $taken->claimed_by);
    }

    // ---------------- P4-9: creation concurrency ----------------

    public function test_p49_concurrent_creation_converges_on_single_task(): void
    {
        $fulfillment = $this->releasedFulfillment(4, 'p49-1');

        $first = $this->orderPicking()->createTasksForFulfillment($fulfillment);
        $second = $this->orderPicking()->createTasksForFulfillment($fulfillment->refresh());

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertEquals($first[0]->id, $second[0]->id);
        $item = $fulfillment->items()->firstOrFail();
        $this->assertEquals(1, PickingTask::where('fulfillment_item_id', $item->id)
            ->whereIn('status', ['pending', 'assigned', 'picking'])->count());
    }
}

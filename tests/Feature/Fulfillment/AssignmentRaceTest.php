<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\PackingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\BatchPickingService;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Fulfillment\PackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 1 (T1) — assignment/start race hardening.
 *
 * Locks serialize concurrent actors; the locked re-check decides the winner.
 * Sequential calls prove the re-check logic (same approach as ConcurrencyAttackTest:
 * true OS-thread parallelism is not available in-process; the DB lock is proven
 * by inspection + the second actor observing the first actor's committed write).
 */
class AssignmentRaceTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Product $product;
    private User $actorA;
    private User $actorB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create([
            'code' => 'WH-1', 'name' => 'Main', 'status' => 'active', 'is_default' => true,
        ]);
        $location = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'A-01',
            'barcode' => 'LOC-A-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-ASSIGN-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);
        $this->actorA = User::factory()->create(['type' => 'customer']);
        $this->actorB = User::factory()->create(['type' => 'customer']);
    }

    private function releasedFulfillment(string $key, int $qty = 2): Fulfillment
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
            'product_sku' => 'SKU-ASSIGN-1', 'product_quantity' => $qty,
            'product_price' => 100, 'product_total_price' => 100 * $qty,
        ]);

        return app(FulfillmentService::class)->releaseForOrder($order->refresh(), $this->warehouse->id, $key);
    }

    public function test_fulfillment_assign_first_winner_wins_second_conflicts(): void
    {
        $fulfillment = $this->releasedFulfillment('assign-1');
        $service = app(FulfillmentService::class);

        $won = $service->assignToUser($fulfillment, $this->actorA->id);
        $this->assertEquals($this->actorA->id, (int) $won->assigned_to);

        // Same user re-assigns: idempotent success.
        $same = $service->assignToUser($fulfillment, $this->actorA->id);
        $this->assertEquals($this->actorA->id, (int) $same->assigned_to);

        // Another user: controlled conflict, assignment preserved.
        try {
            $service->assignToUser($fulfillment, $this->actorB->id);
            $this->fail('second actor must receive a controlled conflict');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already assigned', $e->getMessage());
        }

        $this->assertEquals($this->actorA->id, (int) $fulfillment->refresh()->assigned_to);
    }

    public function test_batch_assign_conflict_and_terminal_rejected(): void
    {
        $f1 = $this->releasedFulfillment('assign-b1');
        $service = app(BatchPickingService::class);
        $batch = $service->createBatchFromFulfillments(collect([$f1]), $this->warehouse->id);

        $service->assignBatch($batch, $this->actorA->id);

        try {
            $service->assignBatch($batch, $this->actorB->id);
            $this->fail('second actor must receive a controlled conflict');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already assigned', $e->getMessage());
        }
        $this->assertEquals($this->actorA->id, (int) $batch->refresh()->assigned_to);

        // Terminal batch cannot be (re)assigned.
        $service->startPicking($batch);
        $started = $batch->refresh();
        $this->assertEquals('picking', $started->status);
        try {
            $service->assignBatch($batch, $this->actorB->id);
            $this->fail('assign on picking batch must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot assign batch', $e->getMessage());
        }
    }

    public function test_start_picking_rejects_wrong_state(): void
    {
        $f1 = $this->releasedFulfillment('assign-b2');
        $service = app(BatchPickingService::class);
        $batch = $service->createBatchFromFulfillments(collect([$f1]), $this->warehouse->id);

        // Pending (not assigned) batch cannot start.
        try {
            $service->startPicking($batch);
            $this->fail('start from pending must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot start picking batch', $e->getMessage());
        }

        // Double start: second rejected.
        $service->assignBatch($batch, $this->actorA->id);
        $service->startPicking($batch);
        try {
            $service->startPicking($batch);
            $this->fail('second start must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot start picking batch', $e->getMessage());
        }
        $this->assertEquals('picking', $batch->refresh()->status);
    }

    private function packingTaskFixture(string $key): PackingTask
    {
        $fulfillment = $this->releasedFulfillment($key);
        $transitions = app(FulfillmentTransition::class);
        $transitions->transition($fulfillment, 'picking');
        $transitions->transition($fulfillment, 'picked');

        return app(PackingService::class)->createPackingTaskFromFulfillment($fulfillment->refresh());
    }

    public function test_station_assign_conflict_and_terminal_rejected(): void
    {
        $task = $this->packingTaskFixture('assign-p1');
        $service = app(PackingService::class);
        $station = PackingStation::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'ST-1',
            'name' => 'Station 1', 'status' => 'active',
        ]);

        $service->assignToStation($task, $station->id, $this->actorA->id);

        try {
            $service->assignToStation($task, $station->id, $this->actorB->id);
            $this->fail('second actor must receive a controlled conflict');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already assigned', $e->getMessage());
        }
        $this->assertEquals($this->actorA->id, (int) $task->refresh()->assigned_to);

        // Terminal (packed) task cannot be reassigned.
        $service->startPacking($task);
        $service->completePacking($task->refresh(), 1.5, ['l' => 10, 'w' => 10, 'h' => 10]);
        try {
            $service->assignToStation($task->refresh(), $station->id, $this->actorB->id);
            $this->fail('assign on packed task must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot assign packing task', $e->getMessage());
        }
    }

    public function test_start_packing_rejects_wrong_state_and_double_start(): void
    {
        $task = $this->packingTaskFixture('assign-p2');
        $service = app(PackingService::class);

        try {
            $service->startPacking($task);
            $this->fail('start from pending must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot start packing task', $e->getMessage());
        }

        $station = PackingStation::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'ST-2',
            'name' => 'Station 2', 'status' => 'active',
        ]);
        $service->assignToStation($task, $station->id, $this->actorA->id);
        $service->startPacking($task);
        try {
            $service->startPacking($task->refresh());
            $this->fail('second start must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot start packing task', $e->getMessage());
        }
        $this->assertEquals('packing', $task->refresh()->status);
    }
}

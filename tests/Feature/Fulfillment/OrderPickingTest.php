<?php

namespace Tests\Feature\Fulfillment;

use App\Exceptions\PickingValidationException;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\OrderPickingService;
use App\Services\Fulfillment\PickingExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 8 — order picking: claim protocol, scan-validated confirm,
 * reject paths, replay idempotency, release/resume.
 */
class OrderPickingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Location $location;
    private Product $product;
    private User $picker;
    private User $other;
    private Fulfillment $fulfillment;

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
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-PICK-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 10, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $this->location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 10, 'allocated_hint' => 0,
        ]);
        $this->picker = User::factory()->create(['type' => 'customer']);
        $this->other = User::factory()->create(['type' => 'customer']);

        $orderUser = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $orderUser->id, 'name' => 'O', 'user_email' => $orderUser->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'P',
            'product_sku' => 'SKU-PICK-1', 'product_quantity' => 4,
            'product_price' => 100, 'product_total_price' => 400,
        ]);
        $this->fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order->refresh(), $this->warehouse->id, 'pick-key');
    }

    private function tasks(): array
    {
        return app(OrderPickingService::class)->createTasksForFulfillment($this->fulfillment);
    }

    private function engine(): PickingExecutionService
    {
        return app(PickingExecutionService::class);
    }

    public function test_claim_is_exclusive_and_reclaim_refreshes_lease(): void
    {
        [$task] = $this->tasks();

        $claimed = $this->engine()->claim($task, $this->picker->id);
        $this->assertEquals('assigned', $claimed->status);
        $this->assertEquals($this->picker->id, (int) $claimed->claimed_by);

        try {
            $this->engine()->claim($task, $this->other->id);
            $this->fail('second worker must not steal the claim');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already claimed', $e->getMessage());
        }

        // Same worker re-claims idempotently.
        $again = $this->engine()->claim($task, $this->picker->id);
        $this->assertEquals('assigned', $again->status);
    }

    public function test_confirm_with_correct_scans_completes_task(): void
    {
        [$task] = $this->tasks();
        $this->engine()->claim($task, $this->picker->id);

        $done = $this->engine()->confirm($task, [
            'location' => 'LOC-A-01', 'product' => 'SKU-PICK-1',
            'quantity' => 4, 'op_seq' => 1,
        ], $this->picker->id);

        $this->assertEquals('picked', $done->status);
        $this->assertEquals(4, (float) $done->quantity_picked);
        // Fan-back to the fulfillment item.
        $this->assertEquals(4, (float) $done->fulfillmentItem->refresh()->quantity_picked);
        // Central counters untouched by picking.
        $this->assertEquals(10, (int) $this->product->refresh()->stock_quantity);
    }

    public function test_confirm_rejects_wrong_location_product_and_overpick(): void
    {
        [$task] = $this->tasks();
        $this->engine()->claim($task, $this->picker->id);

        foreach ([
            ['location' => 'LOC-NOPE', 'product' => 'SKU-PICK-1', 'quantity' => 1, 'op_seq' => 1],
            ['location' => 'LOC-A-01', 'product' => 'SKU-PICK-1', 'quantity' => 99, 'op_seq' => 2],
        ] as $scan) {
            try {
                $this->engine()->confirm($task, $scan, $this->picker->id);
                $this->fail('invalid scan must reject: ' . json_encode($scan));
            } catch (PickingValidationException | \App\Exceptions\UnknownBarcodeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        $this->assertEquals(0, (float) $task->refresh()->quantity_picked);
    }

    public function test_confirm_replay_with_same_op_seq_is_idempotent(): void
    {
        [$task] = $this->tasks();
        $this->engine()->claim($task, $this->picker->id);
        $scan = ['location' => 'LOC-A-01', 'product' => 'SKU-PICK-1', 'quantity' => 2, 'op_seq' => 7];

        $first = $this->engine()->confirm($task, $scan, $this->picker->id);
        $second = $this->engine()->confirm($task, $scan, $this->picker->id);

        $this->assertEquals(2, (float) $second->quantity_picked);
        $this->assertEquals($first->quantity_picked, $second->quantity_picked);
    }

    public function test_release_returns_task_to_pool_keeping_progress(): void
    {
        [$task] = $this->tasks();
        $this->engine()->claim($task, $this->picker->id);
        $this->engine()->confirm($task, [
            'location' => 'LOC-A-01', 'product' => 'SKU-PICK-1',
            'quantity' => 1, 'op_seq' => 1,
        ], $this->picker->id);

        $released = $this->engine()->releaseClaim($task, $this->picker->id);
        $this->assertEquals('pending', $released->status);
        $this->assertNull($released->claimed_by);
        $this->assertEquals(1, (float) $released->quantity_picked);

        // Another worker resumes where the first left off.
        $this->engine()->claim($released, $this->other->id);
        $done = $this->engine()->confirm($released, [
            'location' => 'LOC-A-01', 'product' => 'SKU-PICK-1',
            'quantity' => 3, 'op_seq' => 2,
        ], $this->other->id);
        $this->assertEquals('picked', $done->status);
    }
}

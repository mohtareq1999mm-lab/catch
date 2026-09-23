<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\OrderPickingService;
use App\Services\Fulfillment\PickingExecutionService;
use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 14 — concurrency/idempotency attacks (sqlite-serializable subset;
 * row-lock proof requires MySQL — see matrix notes).
 */
class ConcurrencyAttackTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Product $product;
    private User $pickerA;
    private User $pickerB;

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
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-RACE-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);
        $this->pickerA = User::factory()->create(['type' => 'customer']);
        $this->pickerB = User::factory()->create(['type' => 'customer']);
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
            'product_sku' => 'SKU-RACE-1', 'product_quantity' => $qty,
            'product_price' => 100, 'product_total_price' => 100 * $qty,
        ]);

        return app(FulfillmentService::class)->releaseForOrder($order->refresh(), $this->warehouse->id, $key);
    }

    public function test_expired_claim_can_be_stolen_by_second_worker(): void
    {
        $fulfillment = $this->releasedFulfillment('race-1');
        [$task] = app(OrderPickingService::class)->createTasksForFulfillment($fulfillment);
        $engine = app(PickingExecutionService::class);

        $engine->claim($task, $this->pickerA->id, 15);

        // Lease expires → second worker wins.
        $this->travel(16)->minutes();
        $stolen = $engine->claim($task, $this->pickerB->id, 15);

        $this->assertEquals($this->pickerB->id, (int) $stolen->claimed_by);
        $this->assertEquals('assigned', $stolen->status);
    }

    public function test_other_worker_confirm_is_rejected(): void
    {
        $fulfillment = $this->releasedFulfillment('race-2');
        [$task] = app(OrderPickingService::class)->createTasksForFulfillment($fulfillment);
        $engine = app(PickingExecutionService::class);
        $engine->claim($task, $this->pickerA->id);

        try {
            $engine->confirm($task, [
                'location' => 'LOC-A-01', 'product' => 'SKU-RACE-1',
                'quantity' => 1, 'op_seq' => 1,
            ], $this->pickerB->id);
            $this->fail('foreign confirm must reject');
        } catch (\App\Exceptions\PickingValidationException $e) {
            $this->assertEquals('task_claimed_by_other', $e->context['reason']);
        }

        $this->assertEquals(0, (float) $task->refresh()->quantity_picked);
    }

    public function test_double_completion_is_safe_no_double_effects(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'O', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
            'price' => 100, 'total_price' => 100,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);

        $service = app(OrderService::class);
        $service->changeOrderStatus(null, 'completed', $order->id);
        // Second worker completes the same order again.
        $service->changeOrderStatus(null, 'completed', $order->id);

        $fresh = $order->refresh();
        $this->assertEquals('completed', $fresh->status);
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $fresh->inventory_state);
    }

    public function test_unkeyed_duplicate_release_returns_pending_fulfillment(): void
    {
        $fulfillment = $this->releasedFulfillment('race-3');

        $again = app(FulfillmentService::class)->releaseForOrder(
            $fulfillment->order()->first()->refresh(), $this->warehouse->id
        );

        $this->assertEquals($fulfillment->id, $again->id);
        $this->assertEquals(1, Fulfillment::where('order_id', $fulfillment->order_id)->count());
    }

    public function test_sweeper_releases_expired_claims(): void
    {
        $fulfillment = $this->releasedFulfillment('race-4');
        [$task] = app(OrderPickingService::class)->createTasksForFulfillment($fulfillment);
        app(PickingExecutionService::class)->claim($task, $this->pickerA->id, 15);

        $this->travel(16)->minutes();
        $this->artisan('picking:sweep-expired-claims')->assertExitCode(0);

        $fresh = $task->refresh();
        $this->assertEquals('pending', $fresh->status);
        $this->assertNull($fresh->claimed_by);
    }
}

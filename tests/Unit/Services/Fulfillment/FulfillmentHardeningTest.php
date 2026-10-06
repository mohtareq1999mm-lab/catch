<?php

namespace Tests\Unit\Services\Fulfillment;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\BatchPickingService;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\PickingExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 1 — fulfillment hardening regression (§§5,9,12,14,22,29).
 */
class FulfillmentHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Location $location;
    private Product $product;

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
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-HARD-1',
            'price' => 50, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $this->location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);
    }

    private function makeOrder(array $overrides = []): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create(array_merge([
            'user_id' => $user->id, 'name' => 'O', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 50, 'total_price' => 50,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ], $overrides));
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'P',
            'product_sku' => 'SKU-HARD-1', 'product_quantity' => 2,
            'product_price' => 50, 'product_total_price' => 100,
        ]);

        return $order->refresh();
    }

    public function test_allocation_points_at_product_location_row(): void
    {
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($this->makeOrder(), $this->warehouse->id, 'hard-fk-1');

        $item = $fulfillment->items()->first();
        $this->assertNotNull($item->product_location_id);
        $pl = ProductLocation::find($item->product_location_id);
        $this->assertNotNull($pl, 'product_location_id must reference product_locations');
        $this->assertEquals($this->warehouse->id, (int) $pl->warehouse_id);
        // Snapshot preserved.
        $this->assertEquals('WH-1', $fulfillment->refresh()->warehouse_code);
    }

    public function test_inactive_warehouse_rejected(): void
    {
        $inactive = Warehouse::create(['code' => 'OFF', 'name' => 'Off', 'status' => 'inactive', 'is_default' => false]);

        $this->expectException(\RuntimeException::class);
        app(FulfillmentService::class)->releaseForOrder($this->makeOrder(), $inactive->id, 'hard-inactive-1');
    }

    public function test_cancel_requires_reason_and_preserves_rows(): void
    {
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($this->makeOrder(), $this->warehouse->id, 'hard-cancel-1');

        try {
            app(FulfillmentService::class)->cancelFulfillment($fulfillment, '  ');
            $this->fail('empty reason must throw');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $cancelled = app(FulfillmentService::class)->cancelFulfillment($fulfillment, 'ops void');
        $this->assertEquals('cancelled', $cancelled->status);
        $this->assertDatabaseHas('fulfillments', ['id' => $fulfillment->id, 'status' => 'cancelled']);
    }

    public function test_batch_rejects_mixed_warehouses(): void
    {
        $other = Warehouse::create(['code' => 'WH-2', 'name' => 'Two', 'status' => 'active', 'is_default' => false]);
        $f1 = app(FulfillmentService::class)->releaseForOrder($this->makeOrder(), $this->warehouse->id, 'hard-b1');
        $f2 = app(FulfillmentService::class)->releaseForOrder($this->makeOrder(), $other->id, 'hard-b2');

        $this->expectException(\RuntimeException::class);
        app(BatchPickingService::class)->createBatchFromFulfillments(collect([$f1, $f2]), $this->warehouse->id);
    }

    public function test_batch_rejects_mixed_stages(): void
    {
        $f1 = app(FulfillmentService::class)->releaseForOrder($this->makeOrder(), $this->warehouse->id, 'hard-s1');
        $o2 = $this->makeOrder(['status' => 'processing']);
        // Committed+paid order at a different stage must not batch with pending.
        $f2 = app(FulfillmentService::class)->releaseForOrder($o2, $this->warehouse->id, 'hard-s2');

        $this->expectException(\RuntimeException::class);
        app(BatchPickingService::class)->createBatchFromFulfillments(collect([$f1, $f2]), $this->warehouse->id);
    }

    public function test_reallocate_stays_in_same_warehouse(): void
    {
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($this->makeOrder(), $this->warehouse->id, 'hard-re-1');
        $tasks = app(\App\Services\Fulfillment\OrderPickingService::class)->createTasksForFulfillment($fulfillment);
        $this->assertNotEmpty($tasks);

        $otherWh = Warehouse::create(['code' => 'WH-X', 'name' => 'X', 'status' => 'active', 'is_default' => false]);
        $otherLoc = Location::create([
            'warehouse_id' => $otherWh->id, 'code' => 'Z-01', 'name' => 'Z',
            'type' => 'picking', 'status' => 'active', 'priority' => 1,
        ]);
        $otherPl = ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $otherLoc->id,
            'warehouse_id' => $otherWh->id, 'quantity' => 10, 'allocated_hint' => 0,
        ]);

        $this->expectException(\RuntimeException::class);
        app(PickingExecutionService::class)->reallocateTask($tasks[0], $otherPl->id);
    }
}

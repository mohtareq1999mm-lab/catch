<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\FulfillmentItem;
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
 * Phase 1 (T2) — NULL-allocation item recovery via assignPlacement.
 * Inventory authority assertions: stock / placement quantities never move.
 */
class PlacementAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Warehouse $warehouseB;
    private Product $product;
    private Product $otherProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create([
            'code' => 'WH-1', 'name' => 'Main', 'status' => 'active', 'is_default' => true,
        ]);
        $this->warehouseB = Warehouse::create([
            'code' => 'WH-2', 'name' => 'Second', 'status' => 'active', 'is_default' => false,
        ]);
        // Product under test has NO placement → allocation fails → NULL item.
        $this->product = Product::create([
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-PLACE-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        $this->otherProduct = Product::create([
            'name' => 'Q', 'slug' => 'q-' . uniqid(), 'sku' => 'SKU-PLACE-2',
            'price' => 50, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 20, 'reserved_quantity' => 0,
        ]);
    }

    private function placeableLocation(Warehouse $warehouse, string $code, string $status = 'active'): Location
    {
        return Location::create([
            'warehouse_id' => $warehouse->id, 'code' => $code,
            'barcode' => 'LOC-' . $code, 'name' => 'Bin ' . $code, 'type' => 'picking',
            'status' => $status, 'priority' => 1,
        ]);
    }

    private function place(Product $product, Location $location, float $qty): ProductLocation
    {
        return ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'warehouse_id' => $location->warehouse_id, 'quantity' => $qty, 'allocated_hint' => 0,
        ]);
    }

    private function nullItemFulfillment(string $key, int $qty = 3): FulfillmentItem
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
            'product_sku' => 'SKU-PLACE-1', 'product_quantity' => $qty,
            'product_price' => 100, 'product_total_price' => 100 * $qty,
        ]);

        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order->refresh(), $this->warehouse->id, $key);

        $item = $fulfillment->items()->firstOrFail();
        $this->assertNull($item->product_location_id);

        return $item;
    }

    public function test_null_item_assigned_then_picked_with_authority_intact(): void
    {
        $item = $this->nullItemFulfillment('place-1');
        $placement = $this->place($this->product, $this->placeableLocation($this->warehouse, 'A-01'), 50);

        $stockBefore = (float) $this->product->refresh()->stock_quantity;
        $qtyBefore = (float) $placement->refresh()->quantity;
        $hintBefore = (float) $placement->refresh()->allocated_hint;

        $assigned = app(FulfillmentService::class)->assignPlacement($item, $placement->id);
        $this->assertEquals($placement->id, (int) $assigned->product_location_id);

        // Authority intact: nothing but the item FK moved.
        $this->assertEquals($stockBefore, (float) $this->product->refresh()->stock_quantity);
        $this->assertEquals($qtyBefore, (float) $placement->refresh()->quantity);
        $this->assertEquals($hintBefore, (float) $placement->refresh()->allocated_hint);

        // Existing task flow continues: task created, claimed, confirmed.
        [$task] = app(OrderPickingService::class)->createTasksForFulfillment($assigned->fulfillment);
        $picker = User::factory()->create(['type' => 'customer']);
        $engine = app(PickingExecutionService::class);
        $engine->claim($task, $picker->id);
        $done = $engine->confirm($task, [
            'location' => 'LOC-A-01', 'product' => 'SKU-PLACE-1',
            'quantity' => 3, 'op_seq' => 1,
        ], $picker->id);

        $this->assertEquals('picked', $done->status);
        $this->assertEquals(3, (float) $assigned->refresh()->quantity_picked);
    }

    public function test_wrong_warehouse_rejected(): void
    {
        $item = $this->nullItemFulfillment('place-2');
        $foreign = $this->place($this->product, $this->placeableLocation($this->warehouseB, 'B-01'), 50);

        try {
            app(FulfillmentService::class)->assignPlacement($item, $foreign->id);
            $this->fail('cross-warehouse placement must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('different warehouse', $e->getMessage());
        }
        $this->assertNull($item->refresh()->product_location_id);
    }

    public function test_wrong_product_rejected(): void
    {
        $item = $this->nullItemFulfillment('place-3');
        $wrong = $this->place($this->otherProduct, $this->placeableLocation($this->warehouse, 'A-02'), 20);

        try {
            app(FulfillmentService::class)->assignPlacement($item, $wrong->id);
            $this->fail('wrong-product placement must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('different product', $e->getMessage());
        }
        $this->assertNull($item->refresh()->product_location_id);
    }

    public function test_inactive_location_rejected(): void
    {
        $item = $this->nullItemFulfillment('place-4');
        $dead = $this->place($this->product, $this->placeableLocation($this->warehouse, 'A-03', 'inactive'), 50);

        try {
            app(FulfillmentService::class)->assignPlacement($item, $dead->id);
            $this->fail('inactive-location placement must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not active/placeable', $e->getMessage());
        }
        $this->assertNull($item->refresh()->product_location_id);
    }

    public function test_open_task_blocks_second_assignment(): void
    {
        $item = $this->nullItemFulfillment('place-5', 4);
        $service = app(FulfillmentService::class);
        $first = $this->place($this->product, $this->placeableLocation($this->warehouse, 'A-04'), 50);
        $second = $this->place($this->product, $this->placeableLocation($this->warehouse, 'A-05'), 50);

        $assigned = $service->assignPlacement($item, $first->id);
        app(OrderPickingService::class)->createTasksForFulfillment($assigned->fulfillment);

        try {
            $service->assignPlacement($item, $second->id);
            $this->fail('item with open task must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('open picking task', $e->getMessage());
        }
        $this->assertEquals($first->id, (int) $item->refresh()->product_location_id);
    }
}

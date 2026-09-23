<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Fulfillment\ReturnService;
use App\Services\Inventory\InventoryRestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 12 — recovery: partial sellable returns, no double-restore,
 * duplicate-restock guard, operational cancel.
 */
class ReturnRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Location $location;
    private Product $product;
    private Order $order;

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
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-RET-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 8, 'reserved_quantity' => 0,
            'sold_quantity' => 2,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $this->location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 8, 'allocated_hint' => 0,
        ]);

        // Committed order for 2 units (stock 8, sold 2).
        $user = User::factory()->create(['type' => 'customer']);
        $this->order = Order::create([
            'user_id' => $user->id, 'name' => 'O', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'completed',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
        $this->order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'P',
            'product_sku' => 'SKU-RET-1', 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);
        $this->order->refresh();
    }

    private function shippedFulfillment(): Fulfillment
    {
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($this->order, $this->warehouse->id, 'ret-key-' . uniqid());
        $owner = app(FulfillmentTransition::class);
        foreach (['picking', 'picked', 'packing', 'ready_to_ship', 'shipped'] as $state) {
            $owner->transition($fulfillment, $state);
        }

        return $fulfillment->refresh();
    }

    private function approvedRestockableItem(Fulfillment $fulfillment, int $qty = 1)
    {
        $service = app(ReturnService::class);
        $orderItem = $this->order->orderItems()->firstOrFail();
        $request = $service->createReturnRequest($this->order, [[
            'order_item_id' => $orderItem->id,
            'product_id' => $this->product->id,
            'fulfillment_item_id' => $fulfillment->items()->first()->id,
            'quantity' => $qty,
        ]], 'changed_mind');
        $request = $service->approveReturnRequest($request, [$request->returnItems()->first()->id => $qty], 1);
        $request = $service->markAsReceived($request, 1);
        $request = $service->startInspection($request, 1);
        $item = $request->returnItems()->firstOrFail();
        $service->inspectReturnItem($item, 'good');

        return $item->refresh();
    }

    public function test_sellable_return_restores_central_per_line(): void
    {
        $fulfillment = $this->shippedFulfillment();
        $item = $this->approvedRestockableItem($fulfillment, 1);

        app(ReturnService::class)->restockReturnItem($item, $this->location->id, 1);

        // Central: stock 8→9, sold 2→1. Hint: location 8→9.
        $this->assertEquals(9, (int) $this->product->refresh()->stock_quantity);
        $this->assertEquals(1, (int) $this->product->refresh()->sold_quantity);
        $this->assertEquals(1, (int) $this->order->orderItems()->first()->refresh()->restored_quantity);
    }

    public function test_duplicate_restock_is_rejected(): void
    {
        $fulfillment = $this->shippedFulfillment();
        $item = $this->approvedRestockableItem($fulfillment, 1);

        app(ReturnService::class)->restockReturnItem($item, $this->location->id, 1);
        try {
            app(ReturnService::class)->restockReturnItem($item->refresh(), $this->location->id, 1);
            $this->fail('duplicate restock must reject');
        } catch (\Exception $e) {
            $this->assertStringContainsString('already fully restocked', $e->getMessage());
        }

        // Exactly one unit credited.
        $this->assertEquals(9, (int) $this->product->refresh()->stock_quantity);
    }

    public function test_full_cancel_after_partial_return_does_not_double_credit(): void
    {
        $fulfillment = $this->shippedFulfillment();
        $item = $this->approvedRestockableItem($fulfillment, 1);
        app(ReturnService::class)->restockReturnItem($item, $this->location->id, 1);
        $this->assertEquals(9, (int) $this->product->refresh()->stock_quantity);

        // Full paid cancel restores only the REMAINDER (1 more unit → stock 10).
        $this->assertTrue(app(InventoryRestoreService::class)->restore($this->order));
        $this->assertEquals(10, (int) $this->product->refresh()->stock_quantity);
        $this->assertEquals(0, (int) $this->product->refresh()->sold_quantity);

        // Second restore is a no-op.
        $this->assertFalse(app(InventoryRestoreService::class)->restore($this->order->refresh()));
        $this->assertEquals(10, (int) $this->product->refresh()->stock_quantity);
    }

    public function test_operational_cancel_skips_open_tasks(): void
    {
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($this->order, $this->warehouse->id, 'cancel-key');
        app(FulfillmentTransition::class)->transition($fulfillment, 'picking');

        $task = PickingTask::create([
            'batch_id' => null,
            'fulfillment_item_id' => $fulfillment->items()->first()->id,
            'product_location_id' => ProductLocation::first()->id,
            'order_id' => $this->order->id,
            'quantity_to_pick' => 2, 'quantity_picked' => 0,
            'status' => 'picking', 'sequence' => 1,
        ]);

        $cancelled = app(FulfillmentService::class)->cancelFulfillment($fulfillment, 'customer_cancelled');

        $this->assertEquals('cancelled', $cancelled->status);
        $this->assertEquals('skipped', $task->refresh()->status);
        // Order-level inventory untouched by fulfillment cancel (owned by order path).
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $this->order->refresh()->inventory_state);
    }
}

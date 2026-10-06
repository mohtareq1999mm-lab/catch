<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Fulfillment\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 2 (P2-4) — P2-1 cross-warehouse restock integrity.
 *
 * Return in Warehouse A + location in Warehouse B → rejected BEFORE any
 * write (no ProductLocation, no central stock movement). Same-warehouse
 * restock keeps the exact existing behavior.
 */
class RestockWarehouseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $whA;
    private Warehouse $whB;
    private Location $locA;
    private Location $locB;
    private Product $product;
    private Order $order;
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approver = User::factory()->create(['type' => 'admin']);
        $this->whA = Warehouse::create(['code' => 'WH-A', 'name' => 'A', 'status' => 'active', 'is_default' => true]);
        $this->whB = Warehouse::create(['code' => 'WH-B', 'name' => 'B', 'status' => 'active', 'is_default' => false]);
        $this->locA = Location::create([
            'warehouse_id' => $this->whA->id, 'code' => 'A-01', 'name' => 'Bin A',
            'type' => 'picking', 'status' => 'active', 'priority' => 1,
        ]);
        $this->locB = Location::create([
            'warehouse_id' => $this->whB->id, 'code' => 'B-01', 'name' => 'Bin B',
            'type' => 'picking', 'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-RW-' . strtoupper(uniqid()),
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 8, 'reserved_quantity' => 0,
            'sold_quantity' => 2,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $this->locA->id,
            'warehouse_id' => $this->whA->id, 'quantity' => 8, 'allocated_hint' => 0,
        ]);

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
            'product_sku' => $this->product->sku, 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);
        $this->order->refresh();
    }

    private function approvedRestockableItem(int $qty = 1)
    {
        $service = app(ReturnService::class);
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($this->order, $this->whA->id, 'rw-key-' . uniqid());
        $owner = app(FulfillmentTransition::class);
        foreach (['picking', 'picked', 'packing', 'ready_to_ship', 'shipped'] as $state) {
            $owner->transition($fulfillment, $state);
        }
        $orderItem = $this->order->orderItems()->firstOrFail();
        $request = $service->createReturnRequest($this->order, [[
            'order_item_id' => $orderItem->id,
            'product_id' => $this->product->id,
            'fulfillment_item_id' => $fulfillment->refresh()->items()->first()->id,
            'quantity' => $qty,
        ]], 'changed_mind');
        $request = $service->approveReturnRequest($request, [$request->returnItems()->first()->id => $qty], $this->approver->id);
        $request = $service->markAsReceived($request, $this->approver->id);
        $request = $service->startInspection($request, $this->approver->id);
        $item = $request->returnItems()->firstOrFail();
        $service->inspectReturnItem($item, 'good');

        return $item->refresh();
    }

    public function test_cross_warehouse_restock_rejected_without_any_write(): void
    {
        $item = $this->approvedRestockableItem(1);

        try {
            app(ReturnService::class)->restockReturnItem($item, $this->locB->id, 1);
            $this->fail('cross-warehouse restock must reject');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('belongs to warehouse', $e->getMessage());
        }

        // No invalid placement row created …
        $this->assertEquals(0, ProductLocation::where('product_id', $this->product->id)
            ->where('location_id', $this->locB->id)->count());
        // … return item untouched …
        $this->assertNull($item->refresh()->restocked_at);
        $this->assertEquals(0, (int) $item->refresh()->quantity_restocked);
        // … central stock untouched (restore runs only after the guard).
        $this->assertEquals(8, (int) $this->product->refresh()->stock_quantity);
        $this->assertEquals(2, (int) $this->product->refresh()->sold_quantity);
    }

    public function test_same_warehouse_restock_still_succeeds(): void
    {
        $item = $this->approvedRestockableItem(1);

        app(ReturnService::class)->restockReturnItem($item, $this->locA->id, 1);

        $this->assertEquals(9, (int) $this->product->refresh()->stock_quantity);
        $this->assertEquals(1, (int) $this->product->refresh()->sold_quantity);
        $this->assertNotNull($item->refresh()->restocked_at);
    }
}

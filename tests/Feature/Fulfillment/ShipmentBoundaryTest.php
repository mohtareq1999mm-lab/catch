<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 11 — shipment boundary: ready_to_ship guard, idempotency, dispatch,
 * delivery, order completion rule.
 */
class ShipmentBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Product $product;

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
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-SHIP-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);
    }

    private function makeCompletedOrder(int $qty = 2): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'O', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'completed',
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
            'product_sku' => 'SKU-SHIP-1', 'product_quantity' => $qty,
            'product_price' => 100, 'product_total_price' => 100 * $qty,
        ]);

        return $order->refresh();
    }

    private function makeReadyFulfillment(Order $order, string $key): Fulfillment
    {
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order, $this->warehouse->id, $key);
        $owner = app(FulfillmentTransition::class);
        foreach (['picking', 'picked', 'packing', 'ready_to_ship'] as $state) {
            $owner->transition($fulfillment, $state);
        }

        return $fulfillment->refresh();
    }

    public function test_rejects_shipment_from_non_ready_fulfillment(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order, $this->warehouse->id, 'nb-key');

        try {
            app(ShipmentService::class)->createForFulfillment($fulfillment, [], 'nb-ship');
            $this->fail('non-ready fulfillment must not ship');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ready_to_ship', $e->getMessage());
        }
    }

    public function test_create_is_idempotent_and_dispatch_advances_both(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'd-key');

        $service = app(ShipmentService::class);
        $first = $service->createForFulfillment($fulfillment, ['courier' => 'DHL'], 'ship-key');
        $again = $service->createForFulfillment($fulfillment, ['courier' => 'DHL'], 'ship-key');
        $this->assertEquals($first->id, $again->id);
        $this->assertEquals('label_created', $first->status);
        // Creation alone does not ship the fulfillment.
        $this->assertEquals('ready_to_ship', $fulfillment->refresh()->status);

        $dispatched = $service->dispatch($first->id);
        $this->assertEquals('picked_up', $dispatched->status);
        $this->assertEquals('shipped', $fulfillment->refresh()->status);
    }

    public function test_delivery_completes_order_only_when_all_fulfillments_delivered(): void
    {
        $order = $this->makeCompletedOrder();
        $service = app(ShipmentService::class);

        $f1 = $this->makeReadyFulfillment($order, 'm1');
        $f2 = $this->makeReadyFulfillment($order, 'm2');
        $s1 = $service->createForFulfillment($f1, [], 'ms1');
        $s2 = $service->createForFulfillment($f2, [], 'ms2');

        $service->dispatch($s1->id);
        $service->markDelivered($s1->id);

        // One fulfillment still out → order stays completed, not delivered.
        $this->assertEquals('completed', $order->refresh()->status);
        $this->assertEquals('delivered', $f1->refresh()->status);

        $service->dispatch($s2->id);
        $service->markDelivered($s2->id);

        $this->assertEquals('delivered', $order->refresh()->status);
        $this->assertEquals('delivered', $f2->refresh()->status);
    }
}

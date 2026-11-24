<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\ReturnRequest;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Fulfillment\ReturnService;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

class SplitFulfillmentCarrierReturnLinkTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->warehouse = Warehouse::create([
            'code' => 'WH-174', 'name' => 'WH 174', 'status' => 'active', 'is_default' => true,
        ]);
        $location = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'A-174',
            'barcode' => 'LOC-A-174', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'P174', 'slug' => 'p174-' . uniqid(), 'sku' => 'SKU-174-' . uniqid(),
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);
    }

    public function test_two_fulfillments_produce_two_shipments_and_delivering_one_does_not_complete_order(): void
    {
        $order = $this->makeCompletedOrder(4);
        $f1 = $this->makeReadyFulfillment($order, '174-a');
        $f2 = $this->makeReadyFulfillment($order, '174-b');
        $service = app(ShipmentService::class);

        $s1 = $service->createForFulfillment($f1, [], null);
        $s2 = $service->createForFulfillment($f2, [], null);

        $this->assertNotSame($s1->id, $s2->id);
        $this->assertSame((int) $f1->id, (int) $s1->fulfillment_id);
        $this->assertSame((int) $f2->id, (int) $s2->fulfillment_id);
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());

        $service->dispatch($s1->id);
        $service->markDelivered($s1->id);

        $this->assertSame('delivered', $f1->fresh()->status);
        $this->assertNotSame('delivered', $f2->fresh()->status);
        $this->assertNotSame('delivered', $order->fresh()->status);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_one_active_shipment_per_fulfillment_is_enforced_at_persistence_boundary(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, '174-c');
        $service = app(ShipmentService::class);

        $service->createForFulfillment($fulfillment, [], null);

        $this->expectException(\App\Exceptions\WmsConflictException::class);
        $service->createForFulfillment($fulfillment->fresh(), [], null);
    }

    public function test_carrier_return_links_to_existing_suitable_return_request_and_preserves_provider_metadata(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, '174-d');
        $shipment = app(ShipmentService::class)->createForFulfillment($fulfillment, [], null);
        $user = User::factory()->create(['type' => 'customer']);

        $return = ReturnRequest::create([
            'return_number' => 'RET-174-' . uniqid(),
            'order_id' => $order->id,
            'fulfillment_id' => $fulfillment->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $user->id,
            'status' => 'pending',
            'reason' => 'damaged',
            'requested_by' => $user->id,
            'requested_at' => now(),
            'provider_return_metadata' => ['existing' => 'keep'],
        ]);

        $linked = app(ReturnService::class)->linkCarrierReturn($shipment->fresh(), [
            'provider_code' => 'internal',
            'carrier_reference' => 'CR-174',
        ]);

        $this->assertNotNull($linked);
        $this->assertSame($return->id, $linked->id);
        $this->assertSame((int) $shipment->id, (int) $linked->fresh()->shipment_id);
        $this->assertSame('keep', $linked->fresh()->provider_return_metadata['existing'] ?? null);
        $this->assertSame('CR-174', $linked->fresh()->provider_return_metadata['carrier_reference'] ?? null);
        $this->assertSame(1, ReturnRequest::where('order_id', $order->id)->count());
        $this->assertSame(1, Shipment::where('fulfillment_id', $fulfillment->id)->count());
    }

    public function test_carrier_return_does_not_create_second_shipment_or_parallel_return_lifecycle(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, '174-e');
        $shipment = app(ShipmentService::class)->createForFulfillment($fulfillment, [], null);
        $user = User::factory()->create(['type' => 'customer']);

        $return = ReturnRequest::create([
            'return_number' => 'RET-174-' . uniqid(),
            'order_id' => $order->id,
            'fulfillment_id' => $fulfillment->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $user->id,
            'status' => 'approved',
            'reason' => 'damaged',
            'requested_by' => $user->id,
            'requested_at' => now(),
        ]);

        $beforeShipments = Shipment::count();
        $beforeReturns = ReturnRequest::count();

        $linked = app(ReturnService::class)->linkCarrierReturn($shipment->fresh(), [
            'provider_code' => 'internal',
            'carrier_reference' => 'CR-174-E',
        ]);

        $this->assertSame($return->id, $linked?->id);
        $this->assertSame('approved', $linked?->fresh()->status);
        $this->assertSame($beforeShipments, Shipment::count());
        $this->assertSame($beforeReturns, ReturnRequest::count());
    }

    private function makeCompletedOrder(int $qty = 2): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'O174', 'user_email' => $user->email,
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
            'product_id' => $this->product->id, 'product_name' => 'P174',
            'product_sku' => 'SKU-174', 'product_quantity' => $qty,
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
}

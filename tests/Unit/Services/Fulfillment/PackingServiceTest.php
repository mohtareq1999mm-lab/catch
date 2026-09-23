<?php

namespace Tests\Unit\Services\Fulfillment;

use App\Models\Fulfillment\FulfillmentBatch;
use App\Models\Fulfillment\PackingTask;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Warehouse;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Shipment;
use App\Services\Fulfillment\PackingService;
use App\Services\Fulfillment\FulfillmentTransition;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackingServiceTest extends TestCase
{
    use RefreshDatabase;

    private PackingService $service;
    private Warehouse $warehouse;
    private PackingStation $station;
    private Fulfillment $fulfillment;
    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PackingService(new FulfillmentTransition());

        $this->user = \App\Models\User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->warehouse = Warehouse::create([
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'is_default' => true,
            'status' => 'active',
        ]);

        $this->station = PackingStation::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'PACK-01',
            'name' => 'Packing Station 1',
            'status' => 'active',
            'daily_capacity' => 100,
        ]);

        $this->fulfillment = $this->createFulfillment();
    }

    private function createFulfillment(): Fulfillment
    {
        static $locationCounter = 0;

        $product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product-' . \Illuminate\Support\Str::random(8),
            'price' => 100.00,
            'product_type' => \Marvel\Enums\ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 100,
            'reserved_quantity' => 0,
        ]);

        $order = Order::create([
            'tracking_number' => 'TEST-' . uniqid(),
            'user_id' => $this->user->id,
            'name' => 'Test Customer',
            'user_phone' => '1234567890',
            'user_email' => 'test@example.com',
            'address' => json_encode(['city' => 'Cairo', 'street' => 'Main St']),
            'status' => 'processing',
            'payment_status' => 'paid',
            'price' => 100.00,
            'total_price' => 200.00,
            'shipping_price' => 0,
        ]);

        OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => 'SKU-' . $product->id,
            'product_quantity' => 5,
            'product_price' => $product->price,
            'product_total_price' => $product->price * 5,
        ]);

        $fulfillment = Fulfillment::create([
            'order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'fulfillment_number' => 'FUL-' . uniqid(),
            'status' => 'picked',
            'priority' => 'normal',
        ]);

        $locationCounter++;
        $location = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'A-' . str_pad($locationCounter, 2, '0', STR_PAD_LEFT),
            'name' => 'Location A-' . str_pad($locationCounter, 2, '0', STR_PAD_LEFT),
            'priority' => 10,
            'status' => 'active',
        ]);

        $productLocation = ProductLocation::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 50,
            'reserved_quantity' => 0,
        ]);

        FulfillmentItem::create([
            'fulfillment_id' => $fulfillment->id,
            'order_item_id' => $order->orderItems->first()->id,
            'product_id' => $product->id,
            'product_location_id' => $productLocation->id,
            'quantity' => 5,
            'quantity_picked' => 5,
            'status' => 'picked',
        ]);

        return $fulfillment->fresh(['items']);
    }

    /** @test */
    public function it_creates_packing_task_from_fulfillment()
    {
        $task = $this->service->createPackingTaskFromFulfillment($this->fulfillment);

        $this->assertInstanceOf(PackingTask::class, $task);
        $this->assertEquals($this->fulfillment->id, $task->fulfillment_id);
        $this->assertEquals('pending', $task->status);
        $this->assertEquals('packing', $this->fulfillment->fresh()->status);
    }

    /** @test */
    public function it_prevents_creating_packing_task_from_invalid_fulfillment_status()
    {
        $this->fulfillment->update(['status' => 'pending']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot create packing task from fulfillment in status: pending');

        $this->service->createPackingTaskFromFulfillment($this->fulfillment);
    }

    /** @test */
    public function it_assigns_task_to_station()
    {
        $task = $this->service->createPackingTaskFromFulfillment($this->fulfillment);

        $task = $this->service->assignToStation($task, $this->station->id, $this->user->id);

        $this->assertEquals($this->station->id, $task->packing_station_id);
        $this->assertEquals($this->user->id, $task->assigned_to);
        $this->assertEquals('assigned', $task->status);
        $this->assertNotNull($task->assigned_at);
    }

    /** @test */
    public function it_prevents_assigning_to_inactive_station()
    {
        $this->station->update(['status' => 'inactive']);
        $task = $this->service->createPackingTaskFromFulfillment($this->fulfillment);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Packing station is not active');

        $this->service->assignToStation($task, $this->station->id, $this->user->id);
    }

    /** @test */
    public function it_starts_packing()
    {
        $task = $this->service->createPackingTaskFromFulfillment($this->fulfillment);
        $task = $this->service->assignToStation($task, $this->station->id, $this->user->id);

        $task = $this->service->startPacking($task);

        $this->assertEquals('packing', $task->status);
        $this->assertNotNull($task->started_at);
    }

    /** @test */
    public function it_completes_packing_with_dimensions()
    {
        $task = $this->service->createPackingTaskFromFulfillment($this->fulfillment);
        $task = $this->service->assignToStation($task, $this->station->id, $this->user->id);
        $task = $this->service->startPacking($task);

        $weight = 2.5;
        $dimensions = ['length' => 30, 'width' => 20, 'height' => 10];
        $materials = ['box_type' => 'medium', 'padding' => 'bubble_wrap'];

        $task = $this->service->completePacking($task, $weight, $dimensions, $materials);

        $this->assertEquals('packed', $task->status);
        $this->assertEquals($weight, $task->weight);
        $this->assertEquals($dimensions, $task->dimensions);
        $this->assertEquals($materials, $task->package_materials);
        $this->assertNotNull($task->packed_at);
        $this->assertEquals('packing', $task->fulfillment->fresh()->status);
    }

    /** @test */
    public function it_verifies_packing()
    {
        $task = $this->service->createPackingTaskFromFulfillment($this->fulfillment);
        $task = $this->service->assignToStation($task, $this->station->id, $this->user->id);
        $task = $this->service->startPacking($task);
        $task = $this->service->completePacking($task, 2.5, ['length' => 30, 'width' => 20, 'height' => 10]);

        $task = $this->service->verifyPacking($task, 'Quality check passed');

        $this->assertEquals('verified', $task->status);
        $this->assertNotNull($task->verified_at);
        $this->assertEquals('Quality check passed', $task->notes);
        $this->assertEquals('ready_to_ship', $task->fulfillment->fresh()->status);
    }

    /** @test */
    public function it_creates_shipment_from_verified_packing_task()
    {
        $task = $this->service->createPackingTaskFromFulfillment($this->fulfillment);
        $task = $this->service->assignToStation($task, $this->station->id, $this->user->id);
        $task = $this->service->startPacking($task);
        $task = $this->service->completePacking($task, 2.5, ['length' => 30, 'width' => 20, 'height' => 10]);
        $task = $this->service->verifyPacking($task);

        $shipmentData = [
            'courier' => 'DHL',
            'shipping_method' => 'express',
            'destination_address' => ['city' => 'Cairo', 'street' => 'Main St'],
        ];

        $shipment = $this->service->createShipment($task, $shipmentData);

        $this->assertInstanceOf(Shipment::class, $shipment);
        $this->assertEquals($this->fulfillment->order_id, $shipment->order_id);
        $this->assertEquals($this->fulfillment->id, $shipment->fulfillment_id);
        $this->assertEquals($task->id, $shipment->packing_task_id);
        $this->assertEquals('DHL', $shipment->courier);
        $this->assertEquals('pending', $shipment->status);
        $this->assertEquals(2.5, $shipment->total_weight);
        $this->assertEquals('shipped', $this->fulfillment->fresh()->status);
    }

    /** @test */
    public function it_prevents_creating_shipment_from_unverified_task()
    {
        $task = $this->service->createPackingTaskFromFulfillment($this->fulfillment);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot create shipment from unverified packing task');

        $this->service->createShipment($task, []);
    }

    /** @test */
    public function it_gets_pending_tasks_for_station()
    {
        $task1 = $this->service->createPackingTaskFromFulfillment($this->fulfillment);
        $this->service->assignToStation($task1, $this->station->id, $this->user->id);

        $fulfillment2 = $this->createFulfillment();
        $task2 = $this->service->createPackingTaskFromFulfillment($fulfillment2);
        $this->service->assignToStation($task2, $this->station->id, $this->user->id);

        $tasks = $this->service->getPendingTasksForStation($this->station->id);

        $this->assertCount(2, $tasks);
        $this->assertEquals($task1->id, $tasks->first()->id);
    }

    /** @test */
    public function it_gets_available_stations()
    {
        $station2 = PackingStation::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'PACK-02',
            'name' => 'Packing Station 2',
            'status' => 'active',
            'daily_capacity' => 50,
        ]);

        $stations = $this->service->getAvailableStations($this->warehouse->id);

        $this->assertCount(2, $stations);
        $this->assertTrue($stations->contains('id', $this->station->id));
        $this->assertTrue($stations->contains('id', $station2->id));
    }
}

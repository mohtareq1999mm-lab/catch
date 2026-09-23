<?php

namespace Tests\Unit\Services\Fulfillment;

use App\Models\Fulfillment\FulfillmentBatch;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Warehouse;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Services\Fulfillment\BatchPickingService;
use App\Services\Fulfillment\FulfillmentTransition;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchPickingServiceTest extends TestCase
{
    use RefreshDatabase;

    private BatchPickingService $service;
    private Warehouse $warehouse;
    private Location $locationA;
    private Location $locationB;
    private Product $product1;
    private Product $product2;
    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new BatchPickingService(new FulfillmentTransition());

        // Create test user
        $this->user = \App\Models\User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // Create warehouse and locations
        $this->warehouse = Warehouse::create([
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'is_default' => true,
            'status' => 'active',
        ]);

        $this->locationA = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'A-01',
            'name' => 'Location A-01',
            'priority' => 10,
            'status' => 'active',
        ]);

        $this->locationB = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'B-01',
            'name' => 'Location B-01',
            'priority' => 5,
            'status' => 'active',
        ]);

        // Create products
        $this->product1 = Product::create([
            'name' => 'Product 1',
            'slug' => 'product-1-' . \Illuminate\Support\Str::random(8),
            'price' => 100.00,
            'product_type' => \Marvel\Enums\ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 100,
            'reserved_quantity' => 0,
        ]);

        $this->product2 = Product::create([
            'name' => 'Product 2',
            'slug' => 'product-2-' . \Illuminate\Support\Str::random(8),
            'price' => 50.00,
            'product_type' => \Marvel\Enums\ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 100,
            'reserved_quantity' => 0,
        ]);

        // Create product locations
        ProductLocation::create([
            'product_id' => $this->product1->id,
            'location_id' => $this->locationA->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 50,
            'reserved_quantity' => 0,
        ]);

        ProductLocation::create([
            'product_id' => $this->product2->id,
            'location_id' => $this->locationB->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 30,
            'reserved_quantity' => 0,
        ]);
    }

    private function createFulfillment(Product $product, int $quantity): Fulfillment
    {
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
            'product_quantity' => $quantity,
            'product_price' => $product->price,
            'product_total_price' => $product->price * $quantity,
        ]);

        $fulfillment = Fulfillment::create([
            'order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'fulfillment_number' => 'FUL-' . uniqid(),
            'status' => 'pending',
            'priority' => 'normal',
        ]);

        $productLocation = ProductLocation::where('product_id', $product->id)->first();

        FulfillmentItem::create([
            'fulfillment_id' => $fulfillment->id,
            'order_item_id' => $order->orderItems->first()->id,
            'product_id' => $product->id,
            'product_location_id' => $productLocation->id,
            'quantity' => $quantity,
            'quantity_picked' => 0,
            'status' => 'pending',
        ]);

        return $fulfillment->fresh(['items']);
    }

    /** @test */
    public function it_creates_batch_from_multiple_fulfillments()
    {
        $fulfillment1 = $this->createFulfillment($this->product1, 5);
        $fulfillment2 = $this->createFulfillment($this->product2, 3);

        $fulfillments = collect([$fulfillment1, $fulfillment2]);

        $batch = $this->service->createBatchFromFulfillments($fulfillments);

        $this->assertInstanceOf(FulfillmentBatch::class, $batch);
        $this->assertEquals($this->warehouse->id, $batch->warehouse_id);
        $this->assertEquals('pending', $batch->status);
        $this->assertEquals(2, $batch->total_items);
        $this->assertEquals(0, $batch->picked_items);
        $this->assertStringStartsWith('BATCH-', $batch->batch_number);
    }

    /** @test */
    public function it_creates_picking_tasks_ordered_by_location()
    {
        $fulfillment1 = $this->createFulfillment($this->product1, 5);
        $fulfillment2 = $this->createFulfillment($this->product2, 3);

        $batch = $this->service->createBatchFromFulfillments(
            collect([$fulfillment1, $fulfillment2])
        );

        $this->assertCount(2, $batch->pickingTasks);

        $tasks = $batch->pickingTasks()->bySequence()->get();
        $this->assertEquals(1, $tasks[0]->sequence);
        $this->assertEquals(2, $tasks[1]->sequence);
    }

    /** @test */
    public function it_assigns_batch_to_user()
    {
        $fulfillment = $this->createFulfillment($this->product1, 5);
        $batch = $this->service->createBatchFromFulfillments(collect([$fulfillment]));

        $batch = $this->service->assignBatch($batch, $this->user->id);

        $this->assertEquals($this->user->id, $batch->assigned_to);
        $this->assertEquals('assigned', $batch->status);
    }

    /** @test */
    public function it_starts_picking_batch()
    {
        $fulfillment = $this->createFulfillment($this->product1, 5);
        $batch = $this->service->createBatchFromFulfillments(collect([$fulfillment]));
        $batch = $this->service->assignBatch($batch, $this->user->id);

        $batch = $this->service->startPicking($batch);

        $this->assertEquals('picking', $batch->status);
        $this->assertNotNull($batch->started_at);
    }

    /** @test */
    public function it_records_pick_and_updates_progress()
    {
        $fulfillment = $this->createFulfillment($this->product1, 5);
        $batch = $this->service->createBatchFromFulfillments(collect([$fulfillment]));
        $task = $batch->pickingTasks->first();

        $task = $this->service->recordPick($task, 5);

        $this->assertEquals(5, $task->quantity_picked);
        $this->assertEquals('picked', $task->status);
        $this->assertNotNull($task->picked_at);

        $batch->refresh();
        $this->assertEquals(1, $batch->picked_items);
    }

    /** @test */
    public function it_completes_batch_when_all_tasks_picked()
    {
        $fulfillment = $this->createFulfillment($this->product1, 5);
        $batch = $this->service->createBatchFromFulfillments(collect([$fulfillment]));
        $task = $batch->pickingTasks->first();

        $this->service->recordPick($task, 5);

        $batch->refresh();
        $this->assertEquals('completed', $batch->status);
        $this->assertNotNull($batch->completed_at);
        $this->assertTrue($batch->isComplete());
    }

    /** @test */
    public function it_validates_picked_quantity_does_not_exceed_required()
    {
        $fulfillment = $this->createFulfillment($this->product1, 5);
        $batch = $this->service->createBatchFromFulfillments(collect([$fulfillment]));
        $task = $batch->pickingTasks->first();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('exceeds required quantity');

        $this->service->recordPick($task, 10);
    }

    /** @test */
    public function it_skips_task_with_reason()
    {
        $fulfillment = $this->createFulfillment($this->product1, 5);
        $batch = $this->service->createBatchFromFulfillments(collect([$fulfillment]));
        $task = $batch->pickingTasks->first();

        $task = $this->service->skipTask($task, 'Product damaged');

        $this->assertEquals('skipped', $task->status);
        $this->assertEquals('Product damaged', $task->notes);
    }

    /** @test */
    public function it_cancels_batch_and_skips_pending_tasks()
    {
        $fulfillment = $this->createFulfillment($this->product1, 5);
        $batch = $this->service->createBatchFromFulfillments(collect([$fulfillment]));

        $batch = $this->service->cancelBatch($batch, 'Warehouse closed');

        $this->assertEquals('cancelled', $batch->status);
        $this->assertNotNull($batch->cancelled_at);

        $task = $batch->pickingTasks->first();
        $this->assertEquals('skipped', $task->fresh()->status);
    }

    /** @test */
    public function it_gets_next_task_in_sequence()
    {
        $fulfillment1 = $this->createFulfillment($this->product1, 5);
        $fulfillment2 = $this->createFulfillment($this->product2, 3);
        $batch = $this->service->createBatchFromFulfillments(
            collect([$fulfillment1, $fulfillment2])
        );

        $nextTask = $this->service->getNextTask($batch);

        $this->assertNotNull($nextTask);
        $this->assertEquals(1, $nextTask->sequence);
        $this->assertEquals('pending', $nextTask->status);
    }

    /** @test */
    public function it_calculates_batch_progress_percentage()
    {
        $fulfillment1 = $this->createFulfillment($this->product1, 5);
        $fulfillment2 = $this->createFulfillment($this->product2, 3);
        $batch = $this->service->createBatchFromFulfillments(
            collect([$fulfillment1, $fulfillment2])
        );

        $this->assertEquals(0, $batch->progressPercentage());

        // Pick first task
        $task1 = $batch->pickingTasks->first();
        $this->service->recordPick($task1, 5);

        $batch->refresh();
        $this->assertEquals(50.0, $batch->progressPercentage());
    }
}

<?php

namespace Tests\Unit\Services\Fulfillment;

use App\Models\Fulfillment\ReturnRequest;
use App\Models\Fulfillment\ReturnItem;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Warehouse;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Services\Fulfillment\ReturnService;
use App\Services\Fulfillment\ProductLocationService;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReturnService $service;
    private ProductLocationService $productLocationService;
    private Warehouse $warehouse;
    private Fulfillment $fulfillment;
    private Order $order;
    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productLocationService = new ProductLocationService();
        $this->service = new ReturnService($this->productLocationService);

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

        $this->order = $this->createOrder();
        $this->fulfillment = $this->createFulfillment($this->order);
    }

    private function createOrder(): Order
    {
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
            'status' => 'delivered',
            'payment_status' => 'paid',
            'price' => 100.00,
            'total_price' => 500.00,
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

        return $order->fresh(['orderItems']);
    }

    private function createFulfillment(Order $order): Fulfillment
    {
        $fulfillment = Fulfillment::create([
            'order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'fulfillment_number' => 'FUL-' . uniqid(),
            'status' => 'shipped',
            'priority' => 'normal',
        ]);

        $location = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'A-' . uniqid(),
            'name' => 'Location A',
            'priority' => 10,
            'status' => 'active',
        ]);

        $orderItem = $order->orderItems->first();
        $productLocation = ProductLocation::create([
            'product_id' => $orderItem->product_id,
            'location_id' => $location->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 50,
            'reserved_quantity' => 0,
        ]);

        FulfillmentItem::create([
            'fulfillment_id' => $fulfillment->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $orderItem->product_id,
            'product_location_id' => $productLocation->id,
            'quantity' => 5,
            'quantity_picked' => 5,
            'status' => 'picked',
        ]);

        return $fulfillment->fresh(['items']);
    }

    /** @test */
    public function it_creates_return_request_from_order()
    {
        $orderItem = $this->order->orderItems->first();
        $fulfillmentItem = $this->fulfillment->items->first();

        $items = [
            [
                'order_item_id' => $orderItem->id,
                'product_id' => $orderItem->product_id,
                'fulfillment_item_id' => $fulfillmentItem->id,
                'quantity' => 2,
            ],
        ];

        $returnRequest = $this->service->createReturnRequest(
            $this->order,
            $items,
            'Defective product',
            'Customer notes here',
            $this->user->id
        );

        $this->assertInstanceOf(ReturnRequest::class, $returnRequest);
        $this->assertEquals($this->order->id, $returnRequest->order_id);
        $this->assertEquals($this->fulfillment->id, $returnRequest->fulfillment_id);
        $this->assertEquals('pending', $returnRequest->status);
        $this->assertNotNull($returnRequest->return_number);
        $this->assertCount(1, $returnRequest->returnItems);
        $this->assertEquals(2, $returnRequest->returnItems->first()->quantity_returned);
    }

    /** @test */
    public function it_prevents_return_request_for_order_without_completed_fulfillment()
    {
        $this->fulfillment->update(['status' => 'pending']);

        $orderItem = $this->order->orderItems->first();
        $items = [
            [
                'order_item_id' => $orderItem->id,
                'product_id' => $orderItem->product_id,
                'quantity' => 2,
            ],
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot create return request: order has no completed fulfillment');

        $this->service->createReturnRequest(
            $this->order,
            $items,
            'Defective product',
            null,
            $this->user->id
        );
    }

    /** @test */
    public function it_approves_return_request()
    {
        $returnRequest = $this->createReturnRequest();
        $returnItem = $returnRequest->returnItems->first();

        $approvedQuantities = [
            $returnItem->id => 2,
        ];

        $returnRequest = $this->service->approveReturnRequest(
            $returnRequest,
            $approvedQuantities,
            $this->user->id,
            'Approved for return'
        );

        $this->assertEquals('approved', $returnRequest->status);
        $this->assertEquals($this->user->id, $returnRequest->approved_by);
        $this->assertNotNull($returnRequest->approved_at);
        $this->assertEquals(2, $returnRequest->returnItems->first()->quantity_approved);
    }

    /** @test */
    public function it_rejects_return_request()
    {
        $returnRequest = $this->createReturnRequest();

        $returnRequest = $this->service->rejectReturnRequest(
            $returnRequest,
            'Items not eligible for return',
            $this->user->id
        );

        $this->assertEquals('rejected', $returnRequest->status);
        $this->assertNotNull($returnRequest->rejected_at);
        $this->assertEquals('Items not eligible for return', $returnRequest->admin_notes);
    }

    /** @test */
    public function it_marks_return_as_received()
    {
        $returnRequest = $this->createReturnRequest();
        $this->service->approveReturnRequest(
            $returnRequest,
            [$returnRequest->returnItems->first()->id => 2],
            $this->user->id
        );

        $returnRequest = $this->service->markAsReceived($returnRequest->fresh(), $this->user->id);

        $this->assertEquals('received', $returnRequest->status);
        $this->assertNotNull($returnRequest->received_at);
    }

    /** @test */
    public function it_starts_inspection_process()
    {
        $returnRequest = $this->createReturnRequest();
        $this->service->approveReturnRequest(
            $returnRequest,
            [$returnRequest->returnItems->first()->id => 2],
            $this->user->id
        );
        $this->service->markAsReceived($returnRequest->fresh(), $this->user->id);

        $returnRequest = $this->service->startInspection($returnRequest->fresh(), $this->user->id);

        $this->assertEquals('inspecting', $returnRequest->status);
        $this->assertEquals($this->user->id, $returnRequest->inspected_by);
    }

    /** @test */
    public function it_inspects_return_item()
    {
        $returnRequest = $this->createReturnRequest();
        $returnItem = $returnRequest->returnItems->first();

        $returnItem = $this->service->inspectReturnItem(
            $returnItem,
            'good',
            'Item is in good condition'
        );

        $this->assertEquals('good', $returnItem->condition);
        $this->assertEquals('Item is in good condition', $returnItem->inspection_notes);
        $this->assertNotNull($returnItem->inspected_at);
    }

    /** @test */
    public function it_restocks_return_item_to_location()
    {
        $returnRequest = $this->createReturnRequest();
        $returnItem = $returnRequest->returnItems->first();

        $this->service->approveReturnRequest(
            $returnRequest,
            [$returnItem->id => 2],
            $this->user->id
        );

        $this->service->inspectReturnItem($returnItem->fresh(), 'good');

        $location = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'B-' . uniqid(),
            'name' => 'Return Location',
            'priority' => 5,
            'status' => 'active',
        ]);

        $returnItem = $this->service->restockReturnItem($returnItem->fresh(), $location->id, 2);

        $this->assertEquals(2, $returnItem->quantity_restocked);
        $this->assertEquals($location->id, $returnItem->restocked_location_id);
        $this->assertNotNull($returnItem->restocked_at);
        $this->assertNotNull($returnItem->product_location_id);
    }

    /** @test */
    public function it_prevents_restocking_damaged_items()
    {
        $returnRequest = $this->createReturnRequest();
        $returnItem = $returnRequest->returnItems->first();

        $this->service->approveReturnRequest(
            $returnRequest,
            [$returnItem->id => 2],
            $this->user->id
        );

        $this->service->inspectReturnItem($returnItem->fresh(), 'damaged');

        $location = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'B-' . uniqid(),
            'name' => 'Return Location',
            'priority' => 5,
            'status' => 'active',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot restock item with condition: damaged');

        $this->service->restockReturnItem($returnItem->fresh(), $location->id, 2);
    }

    /** @test */
    public function it_completes_return_request_after_restocking()
    {
        $returnRequest = $this->createReturnRequest();
        $returnItem = $returnRequest->returnItems->first();

        $this->service->approveReturnRequest(
            $returnRequest,
            [$returnItem->id => 2],
            $this->user->id
        );
        $this->service->markAsReceived($returnRequest->fresh(), $this->user->id);
        $this->service->startInspection($returnRequest->fresh(), $this->user->id);
        $this->service->inspectReturnItem($returnItem->fresh(), 'good');

        $location = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'B-' . uniqid(),
            'name' => 'Return Location',
            'priority' => 5,
            'status' => 'active',
        ]);

        $this->service->restockReturnItem($returnItem->fresh(), $location->id, 2);

        $returnRequest = $this->service->completeReturnRequest($returnRequest->fresh());

        $this->assertEquals('completed', $returnRequest->status);
        $this->assertNotNull($returnRequest->completed_at);
    }

    /** @test */
    public function it_gets_pending_returns_for_warehouse()
    {
        $this->createReturnRequest();
        $this->createReturnRequest();

        $pendingReturns = $this->service->getPendingReturns($this->warehouse->id);

        $this->assertCount(2, $pendingReturns);
    }

    /** @test */
    public function it_cancels_return_request()
    {
        $returnRequest = $this->createReturnRequest();

        $returnRequest = $this->service->cancelReturnRequest($returnRequest, 'Customer requested cancellation');

        $this->assertEquals('cancelled', $returnRequest->status);
        $this->assertEquals('Customer requested cancellation', $returnRequest->admin_notes);
    }

    private function createReturnRequest(): ReturnRequest
    {
        $orderItem = $this->order->orderItems->first();
        $fulfillmentItem = $this->fulfillment->items->first();

        $items = [
            [
                'order_item_id' => $orderItem->id,
                'product_id' => $orderItem->product_id,
                'fulfillment_item_id' => $fulfillmentItem->id,
                'quantity' => 2,
            ],
        ];

        return $this->service->createReturnRequest(
            $this->order,
            $items,
            'Defective product',
            'Customer notes here',
            $this->user->id
        );
    }
}

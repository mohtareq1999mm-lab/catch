<?php

namespace Tests\Unit\Services\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Warehouse;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Fulfillment\ProductLocationService;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\OrderProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FulfillmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private FulfillmentService $service;
    private Warehouse $warehouse;
    private Location $location;
    private Product $product;
    private Order $order;
    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new FulfillmentService(new ProductLocationService(), new FulfillmentTransition());

        // Create test user
        $this->user = \App\Models\User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // Create warehouse and location
        $this->warehouse = Warehouse::create([
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'is_default' => true,
            'status' => 'active',
        ]);

        $this->location = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'A-01',
            'name' => 'Location A-01',
            'priority' => 10,
            'status' => 'active',
        ]);

        // Create product with stock
        $this->product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product-' . \Illuminate\Support\Str::random(8),
            'price' => 100.00,
            'product_type' => \Marvel\Enums\ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 100,
            'reserved_quantity' => 0,
        ]);

        // Create product location
        ProductLocation::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 50,
            'reserved_quantity' => 0,
        ]);

        // Create order with order items
        $this->order = Order::create([
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
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_sku' => 'TP-001',
            'product_quantity' => 10,
            'product_price' => 100.00,
            'product_total_price' => 1000.00,
            'order_quantity' => 10,
            'unit_price' => 100.00,
            'subtotal' => 1000.00,
        ]);
    }

    /** @test */
    public function it_creates_fulfillment_from_order()
    {
        $fulfillment = $this->service->createFromOrder($this->order);

        $this->assertInstanceOf(Fulfillment::class, $fulfillment);
        $this->assertEquals($this->order->id, $fulfillment->order_id);
        $this->assertEquals($this->warehouse->id, $fulfillment->warehouse_id);
        $this->assertEquals('pending', $fulfillment->status);
        $this->assertNotNull($fulfillment->fulfillment_number);
        $this->assertStringStartsWith('FUL-', $fulfillment->fulfillment_number);
    }

    /** @test */
    public function it_creates_fulfillment_items_with_location_allocation()
    {
        $fulfillment = $this->service->createFromOrder($this->order);

        $this->assertCount(1, $fulfillment->items);

        $item = $fulfillment->items->first();
        $this->assertEquals($this->product->id, $item->product_id);
        $this->assertEquals(10, $item->quantity);
        $this->assertEquals(0, $item->quantity_picked);
        $this->assertEquals('pending', $item->status);
        $this->assertEquals($this->location->id, $item->product_location_id);
    }

    /** @test */
    public function it_generates_unique_fulfillment_numbers()
    {
        $fulfillment1 = $this->service->createFromOrder($this->order);

        $user2 = \App\Models\User::create([
            'name' => 'Test User 2',
            'email' => 'test2@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $order2 = Order::create([
            'tracking_number' => 'TEST-' . uniqid(),
            'user_id' => $user2->id,
            'name' => 'Test Customer 2',
            'user_phone' => '1234567890',
            'user_email' => 'test2@example.com',
            'address' => json_encode(['city' => 'Cairo', 'street' => 'Main St']),
            'status' => 'processing',
            'payment_status' => 'paid',
            'price' => 100.00,
            'total_price' => 200.00,
            'shipping_price' => 0,
        ]);

        OrderProduct::create([
            'order_id' => $order2->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_sku' => 'TP-001',
            'product_quantity' => 5,
            'product_price' => 100.00,
            'product_total_price' => 500.00,
            'order_quantity' => 5,
            'unit_price' => 100.00,
            'subtotal' => 500.00,
        ]);

        $fulfillment2 = $this->service->createFromOrder($order2);

        $this->assertNotEquals(
            $fulfillment1->fulfillment_number,
            $fulfillment2->fulfillment_number
        );
    }

    /** @test */
    public function it_updates_fulfillment_status_with_valid_transitions()
    {
        $fulfillment = $this->service->createFromOrder($this->order);

        // pending -> picking
        $fulfillment = $this->service->updateStatus($fulfillment, 'picking');
        $this->assertEquals('picking', $fulfillment->status);
        $this->assertNotNull($fulfillment->picking_started_at);

        // picking -> picked
        $fulfillment = $this->service->updateStatus($fulfillment, 'picked');
        $this->assertEquals('picked', $fulfillment->status);
        $this->assertNotNull($fulfillment->picking_completed_at);

        // picked -> packing
        $fulfillment = $this->service->updateStatus($fulfillment, 'packing');
        $this->assertEquals('packing', $fulfillment->status);
        $this->assertNotNull($fulfillment->packing_started_at);

        // packing -> ready_to_ship -> shipped
        $fulfillment = $this->service->updateStatus($fulfillment, 'ready_to_ship');
        $this->assertEquals('ready_to_ship', $fulfillment->status);
        $this->assertNotNull($fulfillment->packing_completed_at);
        $this->assertNotNull($fulfillment->shipped_at ?? $fulfillment->ready_to_ship_at);
        $fulfillment = $this->service->updateStatus($fulfillment, 'shipped');
        $this->assertEquals('shipped', $fulfillment->status);
        $this->assertNotNull($fulfillment->shipped_at);
    }

    /** @test */
    public function it_prevents_invalid_status_transitions()
    {
        $fulfillment = $this->service->createFromOrder($this->order);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid fulfillment transition');

        // pending -> shipped (skipping picking and packing)
        $this->service->updateStatus($fulfillment, 'shipped');
    }

    /** @test */
    public function it_allows_cancellation_from_any_non_terminal_status()
    {
        $fulfillment = $this->service->createFromOrder($this->order);

        // pending -> cancelled
        $fulfillment = $this->service->updateStatus($fulfillment, 'cancelled');
        $this->assertEquals('cancelled', $fulfillment->status);
        $this->assertNotNull($fulfillment->cancelled_at);
    }

    /** @test */
    public function it_assigns_fulfillment_to_user()
    {
        $fulfillment = $this->service->createFromOrder($this->order);
        $userId = 1;

        $fulfillment = $this->service->assignToUser($fulfillment, $userId);

        $this->assertEquals($userId, $fulfillment->assigned_to);
    }

    /** @test */
    public function it_uses_default_warehouse_when_not_specified()
    {
        $fulfillment = $this->service->createFromOrder($this->order);

        $this->assertEquals($this->warehouse->id, $fulfillment->warehouse_id);
    }
}

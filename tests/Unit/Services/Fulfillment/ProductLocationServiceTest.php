<?php

namespace Tests\Unit\Services\Fulfillment;

use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\ProductLocationService;
use Marvel\Database\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductLocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductLocationService $service;
    private Warehouse $warehouse;
    private Location $locationA;
    private Location $locationB;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ProductLocationService();

        // Create warehouse and locations
        $this->warehouse = Warehouse::create([
            'code' => 'TEST',
            'name' => 'Test Warehouse',
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
    }

    /** @test */
    public function it_validates_location_sum_does_not_exceed_stock()
    {
        // Create location quantity exceeding stock
        ProductLocation::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationA->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 120, // Exceeds stock of 100
            'reserved_quantity' => 0,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('exceeds stock quantity');

        $this->service->syncWithStock($this->product->id);
    }

    /** @test */
    public function it_passes_validation_when_location_sum_equals_stock()
    {
        // Create locations totaling exactly stock quantity
        ProductLocation::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationA->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 60,
            'reserved_quantity' => 0,
        ]);

        ProductLocation::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationB->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 40,
            'reserved_quantity' => 0,
        ]);

        // Should not throw
        $this->service->syncWithStock($this->product->id);

        $this->assertTrue(true); // Assertion to prevent risky test warning
    }

    /** @test */
    public function it_allocates_from_highest_priority_location_first()
    {
        ProductLocation::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationA->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 30,
            'reserved_quantity' => 0,
        ]);

        ProductLocation::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationB->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 20,
            'reserved_quantity' => 0,
        ]);

        $allocations = $this->service->allocateFromLocations($this->product->id, 40);

        $this->assertCount(2, $allocations);

        // First allocation from high-priority location A (priority 10)
        $this->assertEquals($this->locationA->id, $allocations[0]['location_id']);
        $this->assertEquals(30, $allocations[0]['allocated']);

        // Second allocation from lower-priority location B (priority 5)
        $this->assertEquals($this->locationB->id, $allocations[1]['location_id']);
        $this->assertEquals(10, $allocations[1]['allocated']);
    }

    /** @test */
    public function it_throws_exception_when_insufficient_stock_in_locations()
    {
        ProductLocation::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationA->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 30,
            'reserved_quantity' => 0,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient stock in locations');

        $this->service->allocateFromLocations($this->product->id, 50);
    }

    /** @test */
    public function it_respects_reserved_quantity_during_allocation()
    {
        ProductLocation::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationA->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 50,
            'reserved_quantity' => 20, // 30 available
        ]);

        $allocations = $this->service->allocateFromLocations($this->product->id, 25);

        $this->assertCount(1, $allocations);
        $this->assertEquals(25, $allocations[0]['allocated']);
        $this->assertEquals(30, $allocations[0]['available']); // Shows available, not total
    }

    /** @test */
    public function it_updates_location_quantity_with_positive_delta()
    {
        $productLocation = ProductLocation::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationA->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 50,
            'reserved_quantity' => 0,
        ]);

        $this->service->updateLocationQuantity($productLocation->id, 25, 'stock_receipt');

        $productLocation->refresh();
        $this->assertEquals(75, $productLocation->quantity);
    }

    /** @test */
    public function it_prevents_quantity_from_going_negative()
    {
        $productLocation = ProductLocation::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationA->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 50,
            'reserved_quantity' => 0,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot reduce quantity below zero');

        $this->service->updateLocationQuantity($productLocation->id, -60);
    }

    /** @test */
    public function it_prevents_quantity_from_dropping_below_reserved()
    {
        $productLocation = ProductLocation::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationA->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 50,
            'reserved_quantity' => 20,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot reduce quantity below reserved amount');

        $this->service->updateLocationQuantity($productLocation->id, -40); // Would leave 10, but reserved is 20
    }

    /** @test */
    public function it_syncs_with_stock_after_quantity_update()
    {
        $productLocation = ProductLocation::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationA->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 90,
            'reserved_quantity' => 0,
        ]);

        // Attempting to add 20 would exceed stock of 100
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('exceeds stock quantity');

        $this->service->updateLocationQuantity($productLocation->id, 20);
    }
}

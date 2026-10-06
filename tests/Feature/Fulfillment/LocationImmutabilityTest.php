<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Product;
use Tests\TestCase;

/**
 * Phase 2 (P2-4) — D-LOC-MOVE: warehouse_id immutable after creation,
 * for EVERY location (placed or empty, active or inactive).
 */
class LocationImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $a;
    private Warehouse $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Warehouse::create(['code' => 'WH-A', 'name' => 'A', 'status' => 'active', 'is_default' => true]);
        $this->b = Warehouse::create(['code' => 'WH-B', 'name' => 'B', 'status' => 'active', 'is_default' => false]);
    }

    private function makeLocation(Warehouse $warehouse, string $code): Location
    {
        return Location::create([
            'warehouse_id' => $warehouse->id, 'code' => $code, 'name' => $code,
            'type' => 'picking', 'status' => 'active', 'priority' => 1,
        ]);
    }

    public function test_warehouse_change_rejected_with_placement(): void
    {
        $location = $this->makeLocation($this->a, 'A-01');
        $product = Product::create([
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-' . strtoupper(uniqid()),
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 5, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'warehouse_id' => $this->a->id, 'quantity' => 5, 'allocated_hint' => 0,
        ]);

        $location->warehouse_id = $this->b->id;
        try {
            $location->save();
            $this->fail('moving a placed location must reject');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
        }

        $this->assertEquals($this->a->id, (int) $location->refresh()->warehouse_id);
        $this->assertEquals($this->a->id, (int) ProductLocation::first()->warehouse_id);
    }

    public function test_warehouse_change_rejected_when_empty(): void
    {
        $location = $this->makeLocation($this->a, 'A-02');

        try {
            $location->update(['warehouse_id' => $this->b->id]);
            $this->fail('moving an empty location must reject');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
        }

        $this->assertEquals($this->a->id, (int) $location->refresh()->warehouse_id);
    }

    public function test_normal_update_of_other_fields_still_works(): void
    {
        $location = $this->makeLocation($this->a, 'A-03');

        $location->update(['name' => 'Renamed', 'priority' => 9, 'status' => 'inactive']);

        $this->assertEquals('Renamed', $location->refresh()->name);
        $this->assertEquals(9, (int) $location->refresh()->priority);
        $this->assertEquals($this->a->id, (int) $location->refresh()->warehouse_id);
    }

    public function test_existing_relationships_remain_valid(): void
    {
        $location = $this->makeLocation($this->a, 'A-04');

        $this->assertEquals($this->a->id, (int) $location->warehouse->id);
        $this->assertTrue($this->a->locations()->whereKey($location->id)->exists());
        $this->assertEquals(0, $location->productLocations()->count());
    }
}

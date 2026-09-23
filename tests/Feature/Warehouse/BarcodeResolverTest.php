<?php

namespace Tests\Feature\Warehouse;

use App\Exceptions\UnknownBarcodeException;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\Warehouse;
use App\Services\Warehouse\BarcodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Product;
use Tests\TestCase;

/**
 * Phase 7 — barcode identity: WHAT vs WHERE vs WHICH, unknown rejected.
 */
class BarcodeResolverTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warehouse = Warehouse::create([
            'code' => 'WH-1', 'name' => 'Main', 'status' => 'active', 'is_default' => true,
        ]);
    }

    public function test_resolves_location_by_barcode(): void
    {
        $location = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'A-01-01',
            'barcode' => 'LOC-WH-1-A-01-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);

        $result = app(BarcodeResolver::class)->resolve('LOC-WH-1-A-01-01', $this->warehouse->id);

        $this->assertEquals('location', $result['kind']);
        $this->assertEquals($location->id, $result['id']);
    }

    public function test_resolves_location_by_code_within_warehouse(): void
    {
        $location = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'B-02',
            'name' => 'Bin', 'type' => 'storage', 'status' => 'active', 'priority' => 1,
        ]);

        $result = app(BarcodeResolver::class)->resolve('B-02', $this->warehouse->id);

        $this->assertEquals('location', $result['kind']);
        $this->assertEquals($location->id, $result['id']);
    }

    public function test_resolves_product_by_sku(): void
    {
        $product = Product::create([
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-RESOLVE-1',
            'price' => 10, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 5,
        ]);

        $result = app(BarcodeResolver::class)->resolve('SKU-RESOLVE-1');

        $this->assertEquals('product', $result['kind']);
        $this->assertEquals($product->id, $result['id']);
    }

    public function test_rejects_blank_and_unknown_codes(): void
    {
        $this->expectException(UnknownBarcodeException::class);
        app(BarcodeResolver::class)->resolve('NOPE-99999');
    }

    public function test_placeable_scope_excludes_quarantine_and_inactive(): void
    {
        foreach (['quarantine', 'damaged', 'returns'] as $i => $type) {
            Location::create([
                'warehouse_id' => $this->warehouse->id, 'code' => "Q-{$i}",
                'name' => $type, 'type' => $type, 'status' => 'active', 'priority' => 1,
            ]);
        }
        Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'Z-99',
            'name' => 'off', 'type' => 'picking', 'status' => 'inactive', 'priority' => 1,
        ]);
        $good = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'G-01',
            'name' => 'good', 'type' => 'picking', 'status' => 'active', 'priority' => 1,
        ]);

        $placeable = Location::placeable()->pluck('code')->all();

        $this->assertEquals(['G-01'], $placeable);
        $this->assertEquals($good->id, Location::placeable()->first()->id);
    }
}

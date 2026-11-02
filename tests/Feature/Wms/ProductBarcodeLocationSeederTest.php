<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\ProductBarcode;
use App\Services\Warehouse\BarcodeResolver;
use App\Services\Warehouse\BarcodeScanService;
use Database\Seeders\ProductBarcodeLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Tests\TestCase;

class ProductBarcodeLocationSeederTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create([
            'code' => 'WH-TEST-DEF',
            'name' => 'Default Test Warehouse',
            'status' => 'active',
            'is_default' => true,
        ]);
    }

    public function test_seeder_assigns_valid_ean13_barcodes_and_provisions_locations(): void
    {
        // 1. Arrange: create simple and variable products
        $simple = Product::create([
            'name' => 'Simple Product 1',
            'slug' => 'simple-prod-1',
            'sku' => 'SKU-SMP-1',
            'price' => 100,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
        ]);

        $variable = Product::create([
            'name' => 'Variable Product 2',
            'slug' => 'var-prod-2',
            'sku' => 'SKU-VAR-2',
            'price' => 200,
            'product_type' => 'variable',
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 20,
        ]);

        $v1 = ProductVariant::create([
            'product_id' => $variable->id,
            'title' => 'Red / L',
            'sku' => 'SKU-VAR-2-RED-L',
            'price' => 200,
            'stock_quantity' => 10,
        ]);

        $v2 = ProductVariant::create([
            'product_id' => $variable->id,
            'title' => 'Blue / M',
            'sku' => 'SKU-VAR-2-BLU-M',
            'price' => 200,
            'stock_quantity' => 10,
        ]);

        // 2. Act: run the seeder
        $this->seed(ProductBarcodeLocationSeeder::class);

        // 3. Assert: ProductBarcode rows
        $this->assertDatabaseHas('product_barcodes', [
            'product_id' => $simple->id,
            'pieces_per_carton' => 12,
        ]);
        $this->assertDatabaseHas('product_barcodes', [
            'product_id' => $variable->id,
            'pieces_per_carton' => 12,
        ]);

        $simpleBarcode = ProductBarcode::where('product_id', $simple->id)->first();
        $this->assertNotNull($simpleBarcode->piece_barcode);
        $this->assertNotNull($simpleBarcode->carton_barcode);
        $this->assertEquals(13, strlen($simpleBarcode->piece_barcode));
        $this->assertEquals(13, strlen($simpleBarcode->carton_barcode));
        $this->assertNotEquals($simpleBarcode->piece_barcode, $simpleBarcode->carton_barcode);
        $this->assertStringStartsWith('6221', $simpleBarcode->piece_barcode);
        $this->assertStringStartsWith('6222', $simpleBarcode->carton_barcode);

        // 4. Assert: Dedicated Picking Locations
        $simpleLocCode = sprintf('PICK-P%03d', $simple->id);
        $this->assertDatabaseHas('locations', [
            'warehouse_id' => $this->warehouse->id,
            'code' => $simpleLocCode,
            'type' => Location::TYPE_PICKING,
            'status' => Location::STATUS_ACTIVE,
        ]);

        $v1LocCode = sprintf('PICK-P%03d-V01', $variable->id);
        $v2LocCode = sprintf('PICK-P%03d-V02', $variable->id);
        $this->assertDatabaseHas('locations', [
            'warehouse_id' => $this->warehouse->id,
            'code' => $v1LocCode,
            'type' => Location::TYPE_PICKING,
        ]);
        $this->assertDatabaseHas('locations', [
            'warehouse_id' => $this->warehouse->id,
            'code' => $v2LocCode,
            'type' => Location::TYPE_PICKING,
        ]);

        // 5. Assert: Placements
        $simpleLoc = Location::where('code', $simpleLocCode)->first();
        $this->assertDatabaseHas('product_locations', [
            'product_id' => $simple->id,
            'product_variant_id' => null,
            'location_id' => $simpleLoc->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 150.00,
            'allocated_hint' => 0.00,
        ]);

        $v1Loc = Location::where('code', $v1LocCode)->first();
        $this->assertDatabaseHas('product_locations', [
            'product_id' => $variable->id,
            'product_variant_id' => $v1->id,
            'location_id' => $v1Loc->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 150.00,
            'allocated_hint' => 0.00,
        ]);

        // 6. Assert: Scanner & Resolver Resolution
        $resolver = app(BarcodeResolver::class);
        $scanner = app(BarcodeScanService::class);

        $resolved = $resolver->resolve($simpleBarcode->piece_barcode);
        $this->assertEquals('product', $resolved['kind']);
        $this->assertEquals($simple->id, $resolved['id']);

        $scanned = $scanner->scan($simpleBarcode->piece_barcode, 'product', $this->warehouse->id);
        $this->assertNotNull($scanned);
        $this->assertEquals($simple->id, $scanned['data']['product_id']);
        $this->assertEquals('piece', $scanned['data']['barcode_type']);
        $this->assertEquals(12, $scanned['data']['pieces_per_carton']);

        $locScanned = $scanner->scan($simpleLoc->barcode, 'location', $this->warehouse->id);
        $this->assertNotNull($locScanned);
        $this->assertEquals($simpleLoc->id, $locScanned['data']['id']);
        $this->assertEquals(1, $locScanned['data']['stored_items_count']);
    }

    public function test_seeder_is_idempotent_on_repeated_runs(): void
    {
        Product::create([
            'name' => 'Repeat Product',
            'slug' => 'repeat-prod',
            'sku' => 'SKU-RPT-1',
            'price' => 50,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 50,
        ]);

        // First run
        $this->seed(ProductBarcodeLocationSeeder::class);
        $countBarcodesFirst = ProductBarcode::count();
        $countLocationsFirst = Location::count();
        $countPlacementsFirst = ProductLocation::count();

        // Second run
        $this->seed(ProductBarcodeLocationSeeder::class);
        $this->assertEquals($countBarcodesFirst, ProductBarcode::count());
        $this->assertEquals($countLocationsFirst, Location::count());
        $this->assertEquals($countPlacementsFirst, ProductLocation::count());
    }
}


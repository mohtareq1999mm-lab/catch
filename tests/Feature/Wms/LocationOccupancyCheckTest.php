<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeProduct;
use Marvel\Database\Models\AttributeValue;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LocationOccupancyCheckTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Warehouse $otherWarehouse;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-warehouse', 'manage-warehouse', 'view-location', 'manage-location',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create([
            'code' => 'WH-TEST-A',
            'name' => 'Warehouse A',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->otherWarehouse = Warehouse::create([
            'code' => 'WH-TEST-B',
            'name' => 'Warehouse B',
            'status' => 'active',
            'is_default' => false,
        ]);

        $this->manager = User::factory()->create([
            'type' => 'staff',
            'warehouse_id' => $this->warehouse->id,
        ]);
        $this->manager->givePermissionTo(['view-location']);
    }

    public function test_requires_authentication_and_view_location_permission(): void
    {
        $payload = ['location_ids' => [1, 2]];

        $this->postJson('/api/v1/admin/locations/check-occupancy', $payload)
            ->assertStatus(401);

        $unauthorizedUser = User::factory()->create(['type' => 'staff', 'warehouse_id' => $this->warehouse->id]);
        $this->actingAs($unauthorizedUser, 'sanctum')
            ->postJson('/api/v1/admin/locations/check-occupancy', $payload)
            ->assertStatus(403);
    }

    public function test_validates_location_ids(): void
    {
        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/admin/locations/check-occupancy', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location_ids']);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/admin/locations/check-occupancy', ['location_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location_ids']);
    }

    public function test_checks_occupancy_correctly_categorizing_empty_and_occupied_locations(): void
    {
        // 1. Completely empty location
        $emptyLoc1 = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'BIN-EMPTY-1',
            'barcode' => 'LOC-BIN-EMPTY-1',
            'name' => 'Empty Bin 1',
            'type' => Location::TYPE_PICKING,
            'status' => Location::STATUS_ACTIVE,
        ]);

        // 2. Location with product_location row but 0 quantity
        $emptyLoc2 = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'BIN-EMPTY-2',
            'barcode' => 'LOC-BIN-EMPTY-2',
            'name' => 'Empty Bin 2',
            'type' => Location::TYPE_STORAGE,
            'status' => Location::STATUS_ACTIVE,
        ]);
        $dummyProduct = Product::create([
            'name' => 'Dummy Product',
            'slug' => 'dummy-product-' . Str::random(6),
            'sku' => 'SKU-DUMMY',
            'price' => 10,
            'status' => 'publish',
        ]);
        ProductLocation::create([
            'product_id' => $dummyProduct->id,
            'location_id' => $emptyLoc2->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 0,
            'allocated_hint' => 0,
        ]);

        // 3. Location with simple product (occupied)
        $occupiedLocSimple = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'BIN-OCC-SIMPLE',
            'barcode' => 'LOC-BIN-OCC-SIMPLE',
            'name' => 'Occupied Simple Bin',
            'type' => Location::TYPE_STORAGE,
            'status' => Location::STATUS_ACTIVE,
        ]);
        $simpleProduct = Product::create([
            'name' => 'Wireless Keyboard',
            'slug' => 'wireless-keyboard-' . Str::random(6),
            'sku' => 'SKU-KEYBOARD-1',
            'price' => 150,
            'status' => 'publish',
        ]);
        ProductLocation::create([
            'product_id' => $simpleProduct->id,
            'location_id' => $occupiedLocSimple->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 15,
            'allocated_hint' => 2,
        ]);

        // 4. Location with variable product and variant attributes (occupied)
        $occupiedLocVariant = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'BIN-OCC-VAR',
            'barcode' => 'LOC-BIN-OCC-VAR',
            'name' => 'Occupied Variant Bin',
            'type' => Location::TYPE_STORAGE,
            'status' => Location::STATUS_ACTIVE,
        ]);
        $variableProduct = Product::create([
            'name' => 'Cotton T-Shirt',
            'slug' => 'cotton-tshirt-' . Str::random(6),
            'sku' => 'SKU-TSHIRT-BASE',
            'price' => 50,
            'status' => 'publish',
        ]);
        $variant = ProductVariant::create([
            'product_id' => $variableProduct->id,
            'sku' => 'VAR-TSHIRT-RED-XL',
            'price' => 60,
            'sale_price' => 55,
            'quantity' => 30,
            'stock_quantity' => 30,
        ]);
        $attrColor = Attribute::create(['name' => 'Color', 'slug' => 'color-' . Str::random(4)]);
        $attrValRed = AttributeValue::create(['attribute_id' => $attrColor->id, 'value' => 'Red']);
        AttributeProduct::create([
            'product_variant_id' => $variant->id,
            'attribute_value_id' => $attrValRed->id,
        ]);
        ProductLocation::create([
            'product_id' => $variableProduct->id,
            'product_variant_id' => $variant->id,
            'location_id' => $occupiedLocVariant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 8,
            'allocated_hint' => 1,
        ]);

        $nonExistentId = 999999;

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/admin/locations/check-occupancy', [
                'location_ids' => [
                    $emptyLoc1->id,
                    $emptyLoc2->id,
                    $occupiedLocSimple->id,
                    $occupiedLocVariant->id,
                    $nonExistentId,
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data');

        // Check empty locations
        $emptyIds = collect($data['empty'])->pluck('id')->all();
        $this->assertContains($emptyLoc1->id, $emptyIds);
        $this->assertContains($emptyLoc2->id, $emptyIds);

        // Check occupied locations
        $occupiedLocations = collect($data['occupied'])->keyBy('id');
        $this->assertTrue($occupiedLocations->has($occupiedLocSimple->id));
        $this->assertTrue($occupiedLocations->has($occupiedLocVariant->id));

        // Check simple product details in occupiedLocSimple
        $simpleData = $occupiedLocations->get($occupiedLocSimple->id);
        $this->assertEquals(15.0, $simpleData['total_quantity']);
        $this->assertEquals(1, $simpleData['products_count']);
        $this->assertEquals($simpleProduct->id, $simpleData['products'][0]['product_id']);
        $this->assertEquals('SKU-KEYBOARD-1', $simpleData['products'][0]['product_sku']);
        $this->assertEquals(15.0, $simpleData['products'][0]['quantity']);
        $this->assertEquals(2.0, $simpleData['products'][0]['allocated_hint']);
        $this->assertEquals(13.0, $simpleData['products'][0]['available_quantity']);
        $this->assertFalse($simpleData['products'][0]['has_variant']);
        $this->assertNull($simpleData['products'][0]['variant']);

        // Check variable product and variant details in occupiedLocVariant
        $varData = $occupiedLocations->get($occupiedLocVariant->id);
        $this->assertEquals(8.0, $varData['total_quantity']);
        $this->assertEquals(1, $varData['products_count']);
        $this->assertEquals($variableProduct->id, $varData['products'][0]['product_id']);
        $this->assertTrue($varData['products'][0]['has_variant']);
        $this->assertNotNull($varData['products'][0]['variant']);
        $this->assertEquals($variant->id, $varData['products'][0]['variant']['id']);
        $this->assertEquals('VAR-TSHIRT-RED-XL', $varData['products'][0]['variant']['sku']);
        $this->assertEquals(60.0, $varData['products'][0]['variant']['price']);
        $this->assertEquals(55.0, $varData['products'][0]['variant']['sale_price']);
        $this->assertNotEmpty($varData['products'][0]['variant']['attributes']);
        $this->assertEquals('Color', $varData['products'][0]['variant']['attributes'][0]['name']);
        $this->assertEquals('Red', $varData['products'][0]['variant']['attributes'][0]['value']);

        // Check not_found
        $this->assertEquals([$nonExistentId], $data['not_found']);
    }

    public function test_supports_locations_alias_in_request(): void
    {
        $loc = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'BIN-ALIAS',
            'name' => 'Alias Bin',
            'type' => Location::TYPE_STORAGE,
            'status' => Location::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/admin/locations/check-occupancy', [
                'locations' => [$loc->id],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $emptyIds = collect($response->json('data.empty'))->pluck('id')->all();
        $this->assertContains($loc->id, $emptyIds);
    }

    public function test_warehouse_scoping_prevents_viewing_foreign_warehouse_locations(): void
    {
        // Foreign location in otherWarehouse
        $foreignLoc = Location::create([
            'warehouse_id' => $this->otherWarehouse->id,
            'code' => 'BIN-FOREIGN',
            'name' => 'Foreign Bin',
            'type' => Location::TYPE_STORAGE,
            'status' => Location::STATUS_ACTIVE,
        ]);

        // Home location in warehouse
        $homeLoc = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'BIN-HOME',
            'name' => 'Home Bin',
            'type' => Location::TYPE_STORAGE,
            'status' => Location::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/admin/locations/check-occupancy', [
                'location_ids' => [$homeLoc->id, $foreignLoc->id],
            ]);

        $response->assertStatus(200);

        $emptyIds = collect($response->json('data.empty'))->pluck('id')->all();
        $this->assertContains($homeLoc->id, $emptyIds);
        $this->assertNotContains($foreignLoc->id, $emptyIds);

        // Foreign location is categorized in not_found for this scoped manager
        $notFound = $response->json('data.not_found');
        $this->assertContains($foreignLoc->id, $notFound);
    }
}

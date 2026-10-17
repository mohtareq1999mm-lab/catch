<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\Type;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as MarvelPermission;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductShowLocationsTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Location $binA1;
    private Location $binA2;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-location', 'manage-location', 'manage-warehouse', 'view-warehouse',
            MarvelPermission::VIEW_PRODUCTS, MarvelPermission::CREATE_PRODUCT,
            MarvelPermission::UPDATE_PRODUCT, MarvelPermission::DELETE_PRODUCT,
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create([
            'code' => 'WH-TEST-' . Str::random(4),
            'name' => 'Main Test Warehouse',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->binA1 = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'LOC-A-01',
            'barcode' => 'BAR-LOC-A01',
            'name' => 'Bin A-01',
            'type' => 'picking',
            'status' => 'active',
        ]);

        $this->binA2 = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'LOC-A-02',
            'barcode' => 'BAR-LOC-A02',
            'name' => 'Bin A-02',
            'type' => 'picking',
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin-' . Str::random(6) . '@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->admin->givePermissionTo([MarvelPermission::VIEW_PRODUCTS, 'view-warehouse', 'view-location']);
    }

    public function test_admin_product_show_returns_locations_on_simple_product(): void
    {
        $product = Product::create([
            'name' => ['en' => 'Test Keyboard Pro'],
            'slug' => 'test-keyboard-pro-' . Str::random(6),
            'description' => ['en' => 'Mechanical keyboard'],
            'price' => 150.00,
            'product_type' => 'simple',
            'status' => 1,
            'in_stock' => true,
            'stock_quantity' => 20,
        ]);

        ProductLocation::create([
            'product_id' => $product->id,
            'location_id' => $this->binA1->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 20,
            'allocated_hint' => 0,
        ]);

        // Test via numeric ID
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}")
            ->assertStatus(200);

        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $product->id);
        $response->assertJsonPath('data.locations.0.location_id', $this->binA1->id);
        $response->assertJsonPath('data.locations.0.location.code', 'LOC-A-01');
        $response->assertJsonPath('data.locations.0.warehouse.code', $this->warehouse->code);
        $this->assertEquals(20.0, (float) $response->json('data.locations.0.quantity'));

        // Test via slug
        $slugResponse = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/products/{$product->slug}")
            ->assertStatus(200);

        $slugResponse->assertJsonPath('data.id', $product->id);
        $slugResponse->assertJsonPath('data.locations.0.location.code', 'LOC-A-01');
    }

    public function test_admin_product_show_returns_locations_on_variants(): void
    {
        $parent = Product::create([
            'name' => ['en' => 'T-Shirt Master'],
            'slug' => 't-shirt-master-' . Str::random(6),
            'description' => ['en' => 'Cotton shirt'],
            'price' => 50.00,
            'product_type' => 'variable',
            'status' => 1,
            'in_stock' => true,
            'stock_quantity' => 0,
        ]);

        $varRed = ProductVariant::create([
            'product_id' => $parent->id,
            'sku' => 'TSHIRT-RED-' . Str::random(4),
            'price' => 50.00,
            'stock_quantity' => 12,
            'in_stock' => true,
        ]);

        ProductLocation::create([
            'product_id' => $parent->id,
            'product_variant_id' => $varRed->id,
            'location_id' => $this->binA2->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 12,
            'allocated_hint' => 0,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/products/{$parent->id}")
            ->assertStatus(200);

        $response->assertJsonPath('data.id', $parent->id);
        // Parent has no direct location (belongs to variant)
        $this->assertEmpty($response->json('data.locations'));

        // Variant has its location
        $this->assertCount(1, $response->json('data.variants'));
        $this->assertEquals($varRed->id, $response->json('data.variants.0.id'));
        $this->assertCount(1, $response->json('data.variants.0.locations'));
        $this->assertEquals('LOC-A-02', $response->json('data.variants.0.locations.0.location.code'));
        $this->assertEquals(12.0, (float) $response->json('data.variants.0.locations.0.quantity'));
    }

    public function test_storefront_product_show_returns_locations_on_product_and_variants(): void
    {
        $product = Product::create([
            'name' => ['en' => 'Storefront Jeans'],
            'slug' => 'storefront-jeans-' . Str::random(6),
            'description' => ['en' => 'Blue jeans'],
            'price' => 80.00,
            'product_type' => 'variable',
            'status' => 1,
            'in_stock' => true,
            'stock_quantity' => 15,
        ]);

        $var32 = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'JEANS-32-' . Str::random(4),
            'price' => 80.00,
            'stock_quantity' => 15,
            'in_stock' => true,
        ]);

        ProductLocation::create([
            'product_id' => $product->id,
            'product_variant_id' => $var32->id,
            'location_id' => $this->binA1->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 15,
            'allocated_hint' => 0,
        ]);

        $response = $this->getJson("/api/v1/general/products/{$product->slug}")
            ->assertStatus(200);

        $response->assertJsonPath('data.id', $product->id);
        $this->assertArrayHasKey('locations', $response->json('data'));
        $this->assertArrayHasKey('variants', $response->json('data'));
        $this->assertCount(1, $response->json('data.variants'));
        $this->assertArrayHasKey('locations', $response->json('data.variants.0'));
        $this->assertEquals('LOC-A-01', $response->json('data.variants.0.locations.0.location.code'));
        $this->assertEquals(15.0, (float) $response->json('data.variants.0.locations.0.quantity'));
    }
}

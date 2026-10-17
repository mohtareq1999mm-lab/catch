<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Database\Repositories\ProductRepository;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProductStoreUpdateOccupancyTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Location $locOccupied;
    private Location $locEmpty1;
    private Location $locEmpty2;
    private Product $existingProduct;
    private User $admin;
    private ProductRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-products', 'create-product', 'update-product', 'manage-warehouse', 'view-location', 'manage-location',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create([
            'code' => 'WH-OCC',
            'name' => 'Warehouse Occupancy',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->locOccupied = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'BIN-OCC-1',
            'name' => 'Occupied Bin',
            'type' => Location::TYPE_STORAGE,
            'status' => Location::STATUS_ACTIVE,
        ]);

        $this->locEmpty1 = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'BIN-EMP-1',
            'name' => 'Empty Bin 1',
            'type' => Location::TYPE_STORAGE,
            'status' => Location::STATUS_ACTIVE,
        ]);

        $this->locEmpty2 = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'BIN-EMP-2',
            'name' => 'Empty Bin 2',
            'type' => Location::TYPE_STORAGE,
            'status' => Location::STATUS_ACTIVE,
        ]);

        $this->existingProduct = Product::create([
            'name' => 'Existing Product',
            'slug' => 'existing-prod-' . Str::random(6),
            'sku' => 'SKU-EXISTING',
            'price' => 100,
            'quantity' => 10,
            'stock_quantity' => 10,
            'product_type' => 'simple',
            'status' => 'publish',
            'in_stock' => true,
        ]);

        ProductLocation::create([
            'product_id' => $this->existingProduct->id,
            'location_id' => $this->locOccupied->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'allocated_hint' => 0,
        ]);

        $this->admin = User::factory()->create([
            'type' => 'super_admin',
            'warehouse_id' => $this->warehouse->id,
        ]);
        $this->admin->givePermissionTo(['view-products', 'create-product', 'update-product', 'manage-warehouse']);

        $this->repo = app(ProductRepository::class);
    }

    public function test_post_product_with_occupied_location_fails_with_422(): void
    {
        $countBefore = Product::count();

        $storeReq = new Request([
            'name' => ['en' => 'New Product Trying Occupied', 'ar' => 'منتج جديد'],
            'description' => ['en' => 'Desc', 'ar' => 'وصف'],
            'product_type' => 'simple',
            'price' => 120,
            'quantity' => 20,
            'locations' => [
                ['location_id' => $this->locEmpty1->id, 'quantity' => 10],
                ['location_id' => $this->locOccupied->id, 'quantity' => 10], // Occupied!
            ],
            'categories' => [],
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
        ]);

        try {
            $this->repo->storeProduct($storeReq);
            $this->fail('Expected HttpException 422 was not thrown');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('is occupied by product', $e->getMessage());
        }

        // Rollback: no product created
        $this->assertSame($countBefore, Product::count());
        $this->assertFalse(ProductLocation::where('location_id', $this->locEmpty1->id)->exists());
    }

    public function test_post_product_with_empty_locations_succeeds(): void
    {
        $storeReq = new Request([
            'name' => ['en' => 'New Product In Empty Bins', 'ar' => 'منتج جديد في فارغ'],
            'description' => ['en' => 'Desc', 'ar' => 'وصف'],
            'product_type' => 'simple',
            'price' => 120,
            'quantity' => 25,
            'locations' => [
                ['location_id' => $this->locEmpty1->id, 'quantity' => 10],
                ['location_id' => $this->locEmpty2->id, 'quantity' => 15],
            ],
            'categories' => [],
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
        ]);

        $product = $this->repo->storeProduct($storeReq);
        $this->assertNotNull($product->id);

        $placements = ProductLocation::where('product_id', $product->id)->get();
        $this->assertCount(2, $placements);
        $this->assertEquals(25, $placements->sum('quantity'));
    }

    public function test_put_product_into_occupied_location_fails_with_422(): void
    {
        // First create a product in empty bin 1
        $prod = Product::create([
            'name' => 'Moveable Product',
            'slug' => 'moveable-prod-' . Str::random(6),
            'sku' => 'SKU-MOVEABLE',
            'price' => 80,
            'quantity' => 5,
            'stock_quantity' => 5,
            'product_type' => 'simple',
            'status' => 'publish',
            'in_stock' => true,
        ]);
        ProductLocation::create([
            'product_id' => $prod->id,
            'location_id' => $this->locEmpty1->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 5,
            'allocated_hint' => 0,
        ]);

        // Attempt to update and move it into locOccupied
        $response = $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/products/{$prod->id}", [
            'name' => ['en' => 'Moveable Product Updated', 'ar' => 'منتج محدث'],
            'price' => 80,
            'quantity' => 5,
            'locations' => [
                ['location_id' => $this->locOccupied->id, 'quantity' => 5], // Occupied by existingProduct!
            ],
            'categories' => [],
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('is occupied by product', $response->json('message'));

        // Location remains unchanged in DB
        $placement = ProductLocation::where('product_id', $prod->id)->first();
        $this->assertSame($this->locEmpty1->id, $placement->location_id);
    }

    public function test_put_product_updating_own_location_succeeds(): void
    {
        $prod = Product::create([
            'name' => 'Self Placed Product',
            'slug' => 'self-prod-' . Str::random(6),
            'sku' => 'SKU-SELF',
            'price' => 90,
            'quantity' => 8,
            'stock_quantity' => 8,
            'product_type' => 'simple',
            'status' => 'publish',
            'in_stock' => true,
        ]);
        ProductLocation::create([
            'product_id' => $prod->id,
            'location_id' => $this->locEmpty1->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 8,
            'allocated_hint' => 0,
        ]);

        // Update quantity in its own location from 8 to 14
        $response = $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/products/{$prod->id}", [
            'name' => ['en' => 'Self Placed Product Updated', 'ar' => 'منتج محدث'],
            'price' => 90,
            'quantity' => 14,
            'locations' => [
                ['location_id' => $this->locEmpty1->id, 'quantity' => 14],
            ],
            'categories' => [],
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
        ]);

        $response->assertStatus(200);

        $placement = ProductLocation::where('product_id', $prod->id)->first();
        $this->assertSame($this->locEmpty1->id, $placement->location_id);
        $this->assertEquals(14.0, (float) $placement->quantity);
    }

    public function test_variable_product_post_fails_when_variant_targets_occupied_location(): void
    {
        $storeReq = new Request([
            'name' => ['en' => 'Variable Prod With Occupied', 'ar' => 'منتج متعدد'],
            'description' => ['en' => 'Desc', 'ar' => 'وصف'],
            'price' => 0,
            'product_type' => 'variable',
            'variants' => [
                [
                    'price' => 50,
                    'quantity' => 10,
                    'locations' => [
                        ['location_id' => $this->locOccupied->id, 'quantity' => 10], // Occupied!
                    ],
                ],
            ],
            'categories' => [],
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
        ]);

        try {
            $this->repo->storeProduct($storeReq);
            $this->fail('Expected HttpException 422 was not thrown');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('is occupied by product', $e->getMessage());
        }
    }
}

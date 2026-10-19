<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BarcodeScanTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private User $admin;
    private Product $product;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'barcode.scan', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'manage-warehouse', 'guard_name' => 'api']);

        $this->warehouse = Warehouse::create([
            'code' => 'WH-SCAN-1',
            'name' => 'Scan Test Warehouse',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->admin = User::factory()->create([
            'email' => 'scanner-admin@test.com',
            'type' => 'admin',
            'warehouse_id' => $this->warehouse->id,
        ]);
        $this->admin->givePermissionTo(['barcode.scan', 'manage-warehouse']);

        $this->location = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'A-01-01',
            'barcode' => 'LOC-BIN-999',
            'name' => 'Shelf A1',
            'type' => 'picking',
            'status' => 'active',
            'priority' => 10,
        ]);

        $this->product = Product::create([
            'name' => 'Scan Test Product',
            'slug' => 'scan-test-' . uniqid(),
            'sku' => '6291041000123', // EAN-13 format
            'price' => 150.00,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 20,
            'reserved_quantity' => 2,
        ]);

        ProductLocation::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 18,
            'allocated_hint' => 0,
        ]);
    }

    public function test_scan_location_by_barcode_with_explicit_type(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/barcode/scan', [
            'code' => 'LOC-BIN-999',
            'type' => 'location',
            'warehouse_id' => $this->warehouse->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'kind' => 'location',
                'detected_by' => 'explicit',
                'data' => [
                    'id' => $this->location->id,
                    'code' => 'A-01-01',
                    'barcode' => 'LOC-BIN-999',
                ],
            ],
        ]);
    }

    public function test_scan_location_by_code_with_explicit_type(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/barcode/scan', [
            'code' => 'A-01-01',
            'type' => 'location',
            'warehouse_id' => $this->warehouse->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('location', $response->json('data.kind'));
        $this->assertEquals($this->location->id, $response->json('data.data.id'));
    }

    public function test_scan_product_by_sku_with_explicit_type(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/barcode/scan', [
            'code' => '6291041000123',
            'type' => 'product',
            'warehouse_id' => $this->warehouse->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'kind' => 'product',
                'detected_by' => 'explicit',
                'data' => [
                    'product_id' => $this->product->id,
                    'sku' => '6291041000123',
                    'stock_quantity' => 20,
                    'available_quantity' => 18,
                ],
            ],
        ]);
        $this->assertNotEmpty($response->json('data.data.placements'));
    }

    public function test_smart_detection_identifies_ean13_product_without_type(): void
    {
        // No 'type' sent: EAN-13 13-digit code should be detected as 'pattern' -> product
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/barcode/scan', [
            'code' => '6291041000123',
            'warehouse_id' => $this->warehouse->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('product', $response->json('data.kind'));
        $this->assertEquals('pattern', $response->json('data.detected_by'));
        $this->assertEquals($this->product->id, $response->json('data.data.product_id'));
    }

    public function test_smart_detection_identifies_rack_format_location_without_type(): void
    {
        // No 'type' sent: 'A-01-01' rack pattern should be detected as 'pattern' -> location
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/barcode/scan', [
            'code' => 'A-01-01',
            'warehouse_id' => $this->warehouse->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('location', $response->json('data.kind'));
        $this->assertEquals('pattern', $response->json('data.detected_by'));
        $this->assertEquals($this->location->id, $response->json('data.data.id'));
    }

    public function test_smart_detection_identifies_loc_prefix_location_without_type(): void
    {
        // No 'type' sent: 'LOC-BIN-999' prefix should be detected as 'pattern' -> location
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/barcode/scan', [
            'code' => 'LOC-BIN-999',
            'warehouse_id' => $this->warehouse->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('location', $response->json('data.kind'));
        $this->assertEquals('pattern', $response->json('data.detected_by'));
        $this->assertEquals($this->location->id, $response->json('data.data.id'));
    }

    public function test_get_method_also_resolves_scan(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/barcode/scan?code=LOC-BIN-999&type=location');

        $response->assertStatus(200);
        $this->assertEquals('location', $response->json('data.kind'));
    }

    public function test_scan_unknown_barcode_returns_404(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/barcode/scan', [
            'code' => 'UNKNOWN-999999',
        ]);

        $response->assertStatus(404);
    }

    public function test_scan_empty_code_fails_validation_with_422(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/barcode/scan', [
            'code' => '',
        ]);

        $response->assertStatus(422);
    }

    public function test_scan_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/admin/barcode/scan', [
            'code' => 'LOC-BIN-999',
        ]);

        $response->assertStatus(401);
    }

    public function test_scan_requires_permission(): void
    {
        $plainUser = User::factory()->create(['type' => 'customer']);

        $response = $this->actingAs($plainUser, 'sanctum')->postJson('/api/v1/admin/barcode/scan', [
            'code' => 'LOC-BIN-999',
        ]);

        $response->assertStatus(403);
    }
}

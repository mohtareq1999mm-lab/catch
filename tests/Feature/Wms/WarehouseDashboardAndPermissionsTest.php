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

class WarehouseDashboardAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private User $admin;
    private Location $location;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'view-warehouse', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'manage-warehouse', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'warehouses.show', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'warehouses.index', 'guard_name' => 'api']);

        $this->warehouse = Warehouse::create([
            'code' => 'WH-TEST-HUB',
            'name' => 'Hub Test Warehouse',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->admin = User::factory()->create([
            'email' => 'hub-admin@test.com',
            'type' => 'admin',
            'warehouse_id' => $this->warehouse->id,
        ]);
        $this->admin->givePermissionTo(['view-warehouse', 'manage-warehouse', 'warehouses.show', 'warehouses.index']);

        $this->location = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'BIN-A1',
            'barcode' => 'LOC-BIN-A1',
            'name' => 'Bin A1',
            'type' => 'bin',
            'status' => 'active',
            'priority' => 10,
        ]);

        $this->product = Product::create([
            'name' => 'Dashboard Test Product',
            'slug' => 'test-product-' . uniqid(),
            'sku' => 'SKU-DASH-100',
            'price' => 200.00,
            'product_type' => 'simple',
            'status' => true,
        ]);

        ProductLocation::create([
            'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->location->id,
            'product_id' => $this->product->id,
            'quantity' => 25,
            'allocated_hint' => 0,
        ]);
    }

    private function authHeaders(User $user): array
    {
        $token = $user->createToken('test-token')->plainTextToken;

        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ];
    }

    public function test_get_warehouse_permissions_endpoint_returns_categorized_modules_and_roles(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->admin))
            ->getJson('/api/v1/admin/warehouses/permissions');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'status',
                'message',
                'success',
                'data' => [
                    'modules',
                    'roles',
                    'my_wms_permissions',
                    'total_wms_permissions',
                ],
            ]);

        $data = $response->json('data');

        $this->assertNotEmpty($data['modules']);
        $this->assertArrayHasKey('picker', $data['roles']);
        $this->assertArrayHasKey('packer', $data['roles']);
        $this->assertArrayHasKey('warehouse-supervisor', $data['roles']);
        $this->assertArrayHasKey('warehouse-manager', $data['roles']);
        $this->assertGreaterThan(20, $data['total_wms_permissions']);

        $moduleKeys = array_column($data['modules'], 'module');
        $this->assertContains('warehouses', $moduleKeys);
        $this->assertContains('locations', $moduleKeys);
        $this->assertContains('picking_and_batches', $moduleKeys);
        $this->assertContains('packing_and_packages', $moduleKeys);
        $this->assertContains('barcode', $moduleKeys);
    }

    public function test_get_warehouse_dashboard_returns_full_operational_metrics(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->admin))
            ->getJson('/api/v1/admin/warehouses/' . $this->warehouse->id . '/dashboard');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'warehouse' => [
                        'id' => $this->warehouse->id,
                        'code' => 'WH-TEST-HUB',
                        'name' => 'Hub Test Warehouse',
                        'status' => 'active',
                        'is_default' => true,
                    ],
                    'locations' => [
                        'total' => 1,
                        'occupied' => 1,
                        'available' => 0,
                        'occupancy_rate_percent' => 100.0,
                    ],
                    'inventory' => [
                        'placements_with_stock' => 1,
                        'unique_products_count' => 1,
                        'total_physical_units' => 25.0,
                    ],
                    'worker_context' => [
                        'user_id' => $this->admin->id,
                        'is_assigned' => true,
                    ],
                ],
            ])
            ->assertJsonStructure([
                'data' => [
                    'warehouse',
                    'locations',
                    'fulfillments',
                    'picking_and_batches',
                    'packing_and_packages',
                    'shipments',
                    'inventory',
                    'worker_context',
                    'quick_actions',
                ],
            ]);
    }


    public function test_get_default_warehouse_dashboard_without_id(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->admin))
            ->getJson('/api/v1/admin/warehouses/dashboard');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'warehouse' => [
                        'id' => $this->warehouse->id,
                        'code' => 'WH-TEST-HUB',
                        'is_default' => true,
                    ],
                ],
            ]);
    }

    public function test_unauthenticated_request_fails_with_401(): void
    {
        $response = $this->getJson('/api/v1/admin/warehouses/' . $this->warehouse->id . '/dashboard');
        $response->assertStatus(401);

        $responsePerm = $this->getJson('/api/v1/admin/warehouses/permissions');
        $responsePerm->assertStatus(401);
    }
}

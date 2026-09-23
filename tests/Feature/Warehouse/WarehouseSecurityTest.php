<?php

namespace Tests\Feature\Warehouse;

use App\Models\Fulfillment\Warehouse;
use App\Services\Warehouse\WarehouseAccess;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 13 — warehouse authorization: roles, financial separation, scoping.
 */
class WarehouseSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouseA;
    private Warehouse $warehouseB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->warehouseA = Warehouse::create([
            'code' => 'WH-A', 'name' => 'A', 'status' => 'active', 'is_default' => true,
        ]);
        $this->warehouseB = Warehouse::create([
            'code' => 'WH-B', 'name' => 'B', 'status' => 'active', 'is_default' => false,
        ]);
    }

    private function makeUser(string $role, ?Warehouse $home = null): User
    {
        $user = User::factory()->create(['type' => 'customer']);
        $user->assignRole($role);
        if ($home) {
            $user->forceFill(['warehouse_id' => $home->id])->save();
        }

        return $user->refresh();
    }

    private function access(): WarehouseAccess
    {
        return app(WarehouseAccess::class);
    }

    public function test_seeder_creates_wms_roles_with_least_privilege(): void
    {
        foreach (['picker', 'packer', 'warehouse-supervisor', 'warehouse-manager'] as $role) {
            $this->assertNotNull(Role::findByName($role, 'api'), "role {$role} seeded");
        }

        $picker = Role::findByName('picker', 'api');
        $this->assertTrue($picker->hasPermissionTo('picking-execute', 'api'));
        // Financial separation: picker holds NO financial/override/admin perms.
        foreach (['update-order-status', 'packing-execute', 'manage-fulfillment', 'fulfillment-override', 'inventory-adjust', 'manage-warehouse'] as $denied) {
            try {
                $this->assertFalse($picker->hasPermissionTo($denied, 'api'), "picker must not hold {$denied}");
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
                $this->assertTrue(true); // absent permission is equally denied
            }
        }

        $supervisor = Role::findByName('warehouse-supervisor', 'api');
        $this->assertTrue($supervisor->hasPermissionTo('fulfillment-override', 'api'));
        $this->assertFalse($supervisor->hasPermissionTo('update-order-status', 'api'));
    }

    public function test_picker_scoped_to_home_warehouse(): void
    {
        $picker = $this->makeUser('picker', $this->warehouseA);

        $this->assertTrue($this->access()->allows($picker, $this->warehouseA->id, 'picking-execute'));
        $this->assertFalse($this->access()->allows($picker, $this->warehouseB->id, 'picking-execute'));
        // Unscoped operation denied even with the permission.
        $this->assertFalse($this->access()->allows($picker, null, 'picking-execute'));
    }

    public function test_user_without_home_warehouse_is_denied(): void
    {
        $picker = $this->makeUser('picker', null);

        $this->assertFalse($this->access()->allows($picker, $this->warehouseA->id, 'picking-execute'));
    }

    public function test_customer_without_permission_is_denied(): void
    {
        $customer = User::factory()->create(['type' => 'customer']);
        $customer->forceFill(['warehouse_id' => $this->warehouseA->id])->save();

        $this->assertFalse($this->access()->allows($customer->refresh(), $this->warehouseA->id, 'picking-execute'));
    }

    public function test_deny_unless_throws_authorization_exception(): void
    {
        $picker = $this->makeUser('picker', $this->warehouseA);

        try {
            $this->access()->denyUnless($picker, $this->warehouseB->id, 'picking-execute');
            $this->fail('cross-warehouse must throw');
        } catch (AuthorizationException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // Allowed path does not throw.
        $this->access()->denyUnless($picker, $this->warehouseA->id, 'picking-execute');
        $this->assertTrue(true);
    }
}

<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Warehouse;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Marvel\Database\Models\User;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * P9-7a: RBAC foundation — source-of-truth permission enumeration, role
 * matrix, fresh/reseed determinism, super_admin ordering fix, owner vs
 * store_owner compatibility, route-permission wiring, and D9-5/D9-15
 * fail-closed proof. Zero new routes; zero domain changes.
 */
class WmsRbacFoundationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Exact WMS set from PermissionSeeder::seedWarehouseRoles(), in source
     * order: 10 coarse + 8 granular = 18. (Discovery's "19" was a miscount;
     * source wins.) If the seeder gains a permission, this test MUST be
     * updated deliberately — never silently.
     *
     * @return string[]
     */
    public static function wmsPermissions(): array
    {
        return [
            'view-warehouse', 'manage-warehouse',
            'view-location', 'manage-location',
            'view-fulfillment', 'manage-fulfillment',
            'picking-execute', 'packing-execute',
            'fulfillment-override', 'inventory-adjust',
            'fulfillment.create', 'fulfillment.cancel',
            'picking.claim', 'picking.complete',
            'packing.complete',
            'batch.manage', 'batch.operate',
            'order.cancel-during-fulfillment',
        ];
    }

    public function test_wms_permission_enumeration_is_exactly_18(): void
    {
        $this->seed(PermissionSeeder::class);

        $names = self::wmsPermissions();
        $this->assertCount(18, $names, 'canonical WMS set must stay exactly 18 until approved');

        foreach ($names as $name) {
            $this->assertNotNull(
                Permission::findByName($name, 'api'),
                "WMS permission {$name} must exist after seed"
            );
        }

        $rows = Permission::whereIn('name', $names)->where('guard_name', 'api')
            ->pluck('name')->all();
        $this->assertCount(count($names), $rows, 'every WMS permission exists exactly once');
        $this->assertCount(count($names), array_unique($rows), 'no duplicate WMS permission rows');
    }

    public function test_role_matrix_matches_source(): void
    {
        $this->seed(PermissionSeeder::class);

        $superAdmin = Role::findByName('super_admin', 'api');
        foreach (self::wmsPermissions() as $name) {
            $this->assertTrue(
                $superAdmin->hasPermissionTo($name, 'api'),
                "super_admin must hold {$name} after fresh seed (D9-14)"
            );
        }

        $expected = [
            'picker' => ['view-warehouse', 'view-location', 'view-fulfillment', 'picking-execute', 'picking.claim'],
            'packer' => ['view-warehouse', 'view-location', 'view-fulfillment', 'packing-execute'],
            'warehouse-supervisor' => [
                'view-warehouse', 'view-location', 'view-fulfillment', 'manage-fulfillment',
                'picking-execute', 'packing-execute', 'fulfillment-override',
                'fulfillment.create', 'fulfillment.cancel',
                'picking.claim', 'picking.complete',
                'packing.complete',
                'batch.manage', 'batch.operate',
                'order.cancel-during-fulfillment',
            ],
            'warehouse-manager' => self::wmsPermissions(),
        ];

        foreach ($expected as $roleName => $grants) {
            $role = Role::findByName($roleName, 'api');
            foreach (self::wmsPermissions() as $name) {
                $this->assertSame(
                    in_array($name, $grants, true),
                    $role->hasPermissionTo($name, 'api'),
                    "{$roleName} " . (in_array($name, $grants, true) ? 'must hold' : 'must NOT hold') . " {$name}"
                );
            }
        }

        foreach (['owner', 'staff', 'customer', 'editor'] as $roleName) {
            $role = Role::findByName($roleName, 'api');
            foreach (self::wmsPermissions() as $name) {
                $this->assertFalse(
                    $role->hasPermissionTo($name, 'api'),
                    "{$roleName} must hold zero WMS grants ({$name})"
                );
            }
        }
    }

    public function test_owner_is_the_seeded_role_and_store_owner_is_unseeded_legacy(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->assertNotNull(Role::findByName('owner', 'api'), 'owner is the DB-canonical role');
        // The legacy constant MUST keep its value: GraphQL abilities and
        // older tests reference 'store_owner' as vocabulary, not as a role.
        $this->assertSame('store_owner', RoleEnum::STORE_OWNER);

        try {
            Role::findByName('store_owner', 'api');
            $this->fail('store_owner must NOT exist as a seeded role (legacy vocabulary only)');
        } catch (\Spatie\Permission\Exceptions\RoleDoesNotExist $e) {
            $this->assertTrue(true);
        }
    }

    public function test_reseed_is_deterministic_with_no_drift(): void
    {
        $this->seed(PermissionSeeder::class);
        $snapshot = $this->snapshotGrants();

        $this->seed(PermissionSeeder::class);
        $this->assertSame($snapshot, $this->snapshotGrants(), 'second seed must be identical');

        $this->seed(PermissionSeeder::class);
        $this->assertSame($snapshot, $this->snapshotGrants(), 'third seed must be identical');
    }

    /**
     * @return array{roles:int,permissions:int,super_admin:int,picker:int}
     */
    private function snapshotGrants(): array
    {
        return [
            'roles' => Role::count(),
            'permissions' => Permission::count(),
            'super_admin' => Role::findByName('super_admin', 'api')->permissions()->count(),
            'picker' => Role::findByName('picker', 'api')->permissions()->count(),
        ];
    }

    public function test_reseed_replaces_role_grants_by_design(): void
    {
        $this->seed(PermissionSeeder::class);

        $custom = Permission::firstOrCreate(['name' => 'wms.custom-probe', 'guard_name' => 'api']);
        Role::findByName('super_admin', 'api')->givePermissionTo($custom);
        $this->assertTrue(
            Role::findByName('super_admin', 'api')->hasPermissionTo('wms.custom-probe', 'api')
        );

        // Matches every other role sync in the seeder: reseed restores the
        // deterministic set. Custom grants belong on custom roles.
        $this->seed(PermissionSeeder::class);
        $this->assertFalse(
            Role::findByName('super_admin', 'api')->hasPermissionTo('wms.custom-probe', 'api'),
            'reseed revokes out-of-set grants (replace semantics, by design)'
        );
        $this->assertTrue(
            Role::findByName('super_admin', 'api')->hasPermissionTo('manage-warehouse', 'api'),
            'seeded grants survive reseed'
        );
    }

    public function test_no_stale_permission_cache_after_seed(): void
    {
        $this->seed(PermissionSeeder::class);

        $user = User::factory()->create(['type' => 'staff']);
        $user->assignRole('picker');

        $this->assertTrue($user->hasPermissionTo('picking-execute', 'api'));
        $this->assertFalse($user->hasPermissionTo('packing-execute', 'api'));
    }

    public function test_d9_5_remains_fail_closed(): void
    {
        $this->seed(PermissionSeeder::class);

        $packer = Role::findByName('packer', 'api');
        $this->assertFalse($packer->hasPermissionTo('packing.complete', 'api'));

        $supervisor = Role::findByName('warehouse-supervisor', 'api');
        foreach (['inventory-adjust', 'manage-warehouse', 'manage-location'] as $name) {
            $this->assertFalse($supervisor->hasPermissionTo($name, 'api'), "supervisor must NOT hold {$name} (D9-5)");
        }
    }

    public function test_d9_15_no_user_warehouse_assignment_route_exists(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->all();
        $hits = array_values(array_filter(
            $uris,
            fn ($uri) => preg_match('#users?/.*warehouse|warehouse.*users?#i', $uri)
        ));

        $this->assertSame([], $hits, 'no user warehouse-assignment API may exist (D9-15)');
    }

    public function test_zero_new_wms_routes_and_legacy_footprint_unchanged(): void
    {
        $names = collect(Route::getRoutes())->map(fn ($r) => $r->getName())->filter()->values()->all();

        $warehouses = array_values(array_filter($names, fn ($n) => str_starts_with($n, 'api.admin.warehouses.')));
        $locations = array_values(array_filter($names, fn ($n) => str_starts_with($n, 'api.admin.locations.')));
        $this->assertCount(8, $warehouses, 'warehouses surface stays exactly 8 routes');
        $this->assertCount(6, $locations, 'locations surface stays exactly 6 routes');

        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression(
                '/^api\.admin\.(fulfillment|picking|packing|batch|shipment)(\.|$)/',
                $name,
                "later-phase route must not exist: {$name}"
            );
        }
        foreach (collect(Route::getRoutes())->map(fn ($r) => $r->uri())->all() as $uri) {
            $this->assertDoesNotMatchRegularExpression(
                '#^api/v1/admin/(fulfillment|picking|packing|batch|shipment)(/|$)#',
                $uri,
                "later-phase URI must not exist: {$uri}"
            );
        }

        $this->assertContains('api.admin.orders.shipment.show', $names);
        $this->assertContains('api.admin.orders.shipment.update-status', $names);
    }

    public function test_p9_2_route_permissions_are_all_seeded(): void
    {
        $this->seed(PermissionSeeder::class);

        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName() ?? '';
            if (!str_starts_with($name, 'api.admin.warehouses.') && !str_starts_with($name, 'api.admin.locations.')) {
                continue;
            }
            foreach ((array) $route->gatherMiddleware() as $middleware) {
                if (!str_starts_with((string) $middleware, 'permission:')) {
                    continue;
                }
                foreach (explode('|', substr((string) $middleware, strlen('permission:'))) as $perm) {
                    $this->assertNotNull(
                        Permission::findByName($perm, 'api'),
                        "route {$name} references seeded permission {$perm}"
                    );
                    $checked++;
                }
            }
        }
        $this->assertSame(14, $checked, 'all 14 P9-2 routes carry exactly one permission each');
    }

    public function test_permission_alone_does_not_grant_cross_warehouse_scope(): void
    {
        $this->seed(PermissionSeeder::class);

        $home = Warehouse::create(['code' => 'WH-RB', 'name' => 'RB', 'status' => 'active']);
        \App\Models\Fulfillment\Location::create([
            'warehouse_id' => $home->id, 'code' => 'RB-1', 'name' => 'RB',
        ]);

        // Attacker G: valid scoped permission, NULL home → denied everywhere.
        $user = User::factory()->create(['type' => 'staff', 'warehouse_id' => null]);
        $user->givePermissionTo('manage-location');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/locations', [
            'warehouse_id' => $home->id, 'code' => 'RB-X', 'name' => 'X',
        ])->assertStatus(403);
        $this->assertDatabaseMissing('locations', ['code' => 'RB-X']);
    }
}

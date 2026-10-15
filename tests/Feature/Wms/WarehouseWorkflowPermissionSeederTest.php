<?php

namespace Tests\Feature\Wms;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Gap D — permission seeder + deployment readiness, isolated only.
 * Never run against shared databases; RefreshDatabase targets catch_verify.
 */
class WarehouseWorkflowPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->assertSame('catch_verify', (string) config('database.connections.mysql.database'), 'Isolated seeder run only');
    }

    public function test_seeder_registers_permission_once_with_correct_role_grants(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        (new PermissionSeeder)->run();
        // Repeatable: second run must not duplicate or drop.
        (new PermissionSeeder)->run();

        $this->assertSame(1, Permission::where('name', 'manage-warehouse-workflow')->where('guard_name', 'api')->count());

        $manager = Role::where('name', 'warehouse-manager')->where('guard_name', 'api')->firstOrFail();
        $super = Role::where('name', 'super_admin')->where('guard_name', 'api')->firstOrFail();
        $this->assertTrue($manager->hasPermissionTo('manage-warehouse-workflow', 'api'));
        $this->assertTrue($super->hasPermissionTo('manage-warehouse-workflow', 'api'));

        foreach (['picker', 'packer', 'warehouse-supervisor'] as $role) {
            $r = Role::where('name', $role)->where('guard_name', 'api')->firstOrFail();
            $this->assertFalse($r->hasPermissionTo('manage-warehouse-workflow', 'api'), "{$role} must not hold workflow config");
        }

        // Labels resolve in both locales (no raw keys).
        app()->setLocale('en');
        $this->assertSame('Manage warehouse workflow', __('permissions.manage-warehouse-workflow'));
        app()->setLocale('ar');
        $this->assertNotSame('permissions.manage-warehouse-workflow', __('permissions.manage-warehouse-workflow'));
        app()->setLocale('en');
    }

    public function test_route_enforcement_matches_seeded_roles(): void
    {
        (new PermissionSeeder)->run();

        $picker = User::factory()->create(['type' => 'staff']);
        $picker->assignRole('picker');
        $this->actingAs($picker, 'sanctum')->getJson('/api/v1/admin/warehouse-workflow')->assertStatus(403);
        $this->actingAs($picker, 'sanctum')->postJson('/api/v1/admin/warehouse-workflow/publish', [
            'shipping_enabled' => true,
        ])->assertStatus(403);

        $manager = User::factory()->create(['type' => 'staff']);
        $manager->assignRole('warehouse-manager');
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/admin/warehouse-workflow')->assertOk();
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/warehouse-workflow/publish', [
            'shipping_enabled' => true,
        ])->assertCreated();
    }
}

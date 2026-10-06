<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * P9-2: Warehouse / Location HTTP adapter.
 *
 * Auth (401), permission (403), warehouse scope (same/different/null →
 * allow/403/404/deny), lifecycle guards (default protection, uniqueness,
 * warehouse immutability), mass-assignment resistance, and IDOR.
 */
class WmsWarehouseLocationTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['view-warehouse', 'manage-warehouse', 'view-location', 'manage-location'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouseA = Warehouse::create([
            'code' => 'WH-A', 'name' => 'Alpha', 'status' => 'active', 'is_default' => true,
        ]);
        $this->warehouseB = Warehouse::create([
            'code' => 'WH-B', 'name' => 'Beta', 'status' => 'active', 'is_default' => false,
        ]);
    }

    private function user(?int $home, array $permissions = [], string $type = 'staff'): User
    {
        $user = User::factory()->create(['type' => $type, 'warehouse_id' => $home]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function manager(?int $home = null): User
    {
        return $this->user($home ?? $this->warehouseA->id, [
            'view-warehouse', 'manage-warehouse', 'view-location', 'manage-location',
        ]);
    }

    // ---------- authentication ----------

    public function test_unauthenticated_warehouse_routes_return_401(): void
    {
        $this->getJson('/api/v1/admin/warehouses')->assertStatus(401);
        $this->postJson('/api/v1/admin/warehouses', [])->assertStatus(401);
        $this->getJson('/api/v1/admin/locations')->assertStatus(401);
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $user = $this->user($this->warehouseA->id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/warehouses')->assertStatus(403);
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/locations')->assertStatus(403);
    }

    public function test_viewer_cannot_write(): void
    {
        $user = $this->user($this->warehouseA->id, ['view-warehouse', 'view-location']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/warehouses', ['code' => 'WH-X', 'name' => 'X'])
            ->assertStatus(403);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/locations', [
                'warehouse_id' => $this->warehouseA->id, 'code' => 'L-X', 'name' => 'X',
            ])->assertStatus(403);
    }

    // ---------- warehouse reads / scope ----------

    public function test_index_scopes_to_home_warehouse_without_global_permission(): void
    {
        $user = $this->user($this->warehouseA->id, ['view-warehouse']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/warehouses');

        $response->assertStatus(200)->assertJsonFragment(['code' => 'WH-A']);
        $response->assertJsonMissing(['code' => 'WH-B']);
    }

    public function test_index_returns_all_for_global_permission(): void
    {
        $this->actingAs($this->manager(), 'sanctum')
            ->getJson('/api/v1/admin/warehouses')
            ->assertStatus(200)
            ->assertJsonFragment(['code' => 'WH-A'])
            ->assertJsonFragment(['code' => 'WH-B']);
    }

    public function test_index_with_null_home_fails_closed(): void
    {
        $user = $this->user(null, ['view-warehouse']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/warehouses')->assertStatus(403);
    }

    public function test_show_same_warehouse_returns_200(): void
    {
        $user = $this->user($this->warehouseA->id, ['view-warehouse']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/admin/warehouses/{$this->warehouseA->id}")
            ->assertStatus(200)
            ->assertJsonFragment(['code' => 'WH-A']);
    }

    public function test_show_cross_warehouse_returns_404_anti_enumeration(): void
    {
        $user = $this->user($this->warehouseA->id, ['view-warehouse']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/admin/warehouses/{$this->warehouseB->id}")
            ->assertStatus(404);
    }

    public function test_show_missing_returns_404(): void
    {
        $this->actingAs($this->manager(), 'sanctum')
            ->getJson('/api/v1/admin/warehouses/999999')->assertStatus(404);
    }

    // ---------- warehouse writes ----------

    public function test_store_creates_warehouse(): void
    {
        $response = $this->actingAs($this->manager(), 'sanctum')->postJson(
            '/api/v1/admin/warehouses',
            ['code' => 'WH-C', 'name' => 'Gamma', 'city' => 'Cairo']
        );

        $response->assertStatus(201)->assertJsonFragment(['code' => 'WH-C']);
        $this->assertDatabaseHas('warehouses', ['code' => 'WH-C', 'status' => 'active']);
    }

    public function test_store_duplicate_code_returns_422(): void
    {
        $this->actingAs($this->manager(), 'sanctum')->postJson(
            '/api/v1/admin/warehouses',
            ['code' => 'WH-A', 'name' => 'Dupe']
        )->assertStatus(422);
    }

    public function test_store_with_default_promotes_and_unsets_previous(): void
    {
        $this->actingAs($this->manager(), 'sanctum')->postJson(
            '/api/v1/admin/warehouses',
            ['code' => 'WH-D', 'name' => 'Delta', 'is_default' => true]
        )->assertStatus(201);

        $this->assertTrue(Warehouse::where('code', 'WH-D')->first()->is_default);
        $this->assertFalse($this->warehouseA->fresh()->is_default);
    }

    public function test_store_with_default_on_inactive_returns_422(): void
    {
        $this->actingAs($this->manager(), 'sanctum')->postJson(
            '/api/v1/admin/warehouses',
            ['code' => 'WH-E', 'name' => 'Eps', 'status' => 'inactive', 'is_default' => true]
        )->assertStatus(422);
    }

    public function test_update_changes_metadata_only_and_ignores_protected_fields(): void
    {
        $this->actingAs($this->manager(), 'sanctum')->putJson(
            "/api/v1/admin/warehouses/{$this->warehouseB->id}",
            [
                'name' => 'Beta Renamed',
                'code' => 'WH-HACK',
                'status' => 'inactive',
                'is_default' => true,
            ]
        )->assertStatus(200)->assertJsonFragment(['name' => 'Beta Renamed']);

        $fresh = $this->warehouseB->fresh();
        $this->assertSame('Beta Renamed', $fresh->name);
        $this->assertSame('WH-B', $fresh->code);
        $this->assertSame('active', $fresh->status);
        $this->assertFalse((bool) $fresh->is_default);
    }

    public function test_update_requires_manage_permission_even_same_home(): void
    {
        // manage-warehouse is the approved global bypass (WarehouseAccess):
        // a holder may update any warehouse. Least privilege is enforced by
        // the permission itself — a same-home viewer is denied the write.
        $global = $this->user($this->warehouseB->id, ['manage-warehouse', 'view-warehouse']);

        $this->actingAs($global, 'sanctum')->putJson(
            "/api/v1/admin/warehouses/{$this->warehouseA->id}",
            ['name' => 'Alpha Global']
        )->assertStatus(200);
        $this->assertSame('Alpha Global', $this->warehouseA->fresh()->name);

        $viewer = $this->user($this->warehouseA->id, ['view-warehouse']);

        $this->actingAs($viewer, 'sanctum')->putJson(
            "/api/v1/admin/warehouses/{$this->warehouseA->id}",
            ['name' => 'Hijack']
        )->assertStatus(403);
    }

    public function test_set_default_moves_flag(): void
    {
        $this->actingAs($this->manager(), 'sanctum')
            ->postJson("/api/v1/admin/warehouses/{$this->warehouseB->id}/set-default")
            ->assertStatus(200);

        $this->assertTrue($this->warehouseB->fresh()->is_default);
        $this->assertFalse($this->warehouseA->fresh()->is_default);
    }

    public function test_set_default_on_inactive_returns_422(): void
    {
        $this->warehouseB->update(['status' => 'inactive']);

        $this->actingAs($this->manager(), 'sanctum')
            ->postJson("/api/v1/admin/warehouses/{$this->warehouseB->id}/set-default")
            ->assertStatus(422);
    }

    public function test_deactivate_default_returns_422_and_deactivate_other_succeeds(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/warehouses/{$this->warehouseA->id}/deactivate")
            ->assertStatus(422);
        $this->assertSame('active', $this->warehouseA->fresh()->status);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/warehouses/{$this->warehouseB->id}/deactivate")
            ->assertStatus(200);
        $this->assertSame('inactive', $this->warehouseB->fresh()->status);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/warehouses/{$this->warehouseB->id}/activate")
            ->assertStatus(200);
        $this->assertSame('active', $this->warehouseB->fresh()->status);
    }

    public function test_destroy_soft_deletes_non_default_and_blocks_default(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/warehouses/{$this->warehouseB->id}")
            ->assertStatus(200);
        $this->assertSoftDeleted('warehouses', ['code' => 'WH-B']);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/warehouses/{$this->warehouseA->id}")
            ->assertStatus(422);
        $this->assertDatabaseHas('warehouses', ['code' => 'WH-A', 'deleted_at' => null]);
    }

    // ---------- locations ----------

    public function test_location_index_scopes_to_home_warehouse(): void
    {
        Location::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'A-01', 'name' => 'Bin A',
        ]);
        Location::create([
            'warehouse_id' => $this->warehouseB->id, 'code' => 'B-01', 'name' => 'Bin B',
        ]);
        $user = $this->user($this->warehouseA->id, ['view-location']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/locations');

        $response->assertStatus(200)->assertJsonFragment(['code' => 'A-01']);
        $response->assertJsonMissing(['code' => 'B-01']);
    }

    public function test_location_show_cross_warehouse_returns_404(): void
    {
        $other = Location::create([
            'warehouse_id' => $this->warehouseB->id, 'code' => 'B-09', 'name' => 'Bin',
        ]);
        $user = $this->user($this->warehouseA->id, ['view-location']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/admin/locations/{$other->id}")
            ->assertStatus(404);
    }

    public function test_location_store_and_duplicate_code_rules(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/locations', [
            'warehouse_id' => $this->warehouseA->id, 'code' => 'A-01',
            'name' => 'Bin', 'type' => 'picking', 'priority' => 5,
        ])->assertStatus(201)->assertJsonFragment(['code' => 'A-01']);

        // Same code, same warehouse → 422.
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/locations', [
            'warehouse_id' => $this->warehouseA->id, 'code' => 'A-01', 'name' => 'Dupe',
        ])->assertStatus(422);

        // Same code, different warehouse → allowed.
        $managerB = $this->user($this->warehouseB->id, ['manage-location']);
        $this->actingAs($managerB, 'sanctum')->postJson('/api/v1/admin/locations', [
            'warehouse_id' => $this->warehouseB->id, 'code' => 'A-01', 'name' => 'Bin B',
        ])->assertStatus(201);
    }

    public function test_location_store_rejects_cross_warehouse_parent_and_bad_type(): void
    {
        $manager = $this->manager();
        $parentA = Location::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'PA', 'name' => 'Parent',
        ]);
        $managerB = $this->user($this->warehouseB->id, ['manage-location']);

        $this->actingAs($managerB, 'sanctum')->postJson('/api/v1/admin/locations', [
            'warehouse_id' => $this->warehouseB->id, 'code' => 'CB',
            'name' => 'Child', 'parent_id' => $parentA->id,
        ])->assertStatus(422);

        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/locations', [
            'warehouse_id' => $this->warehouseA->id, 'code' => 'T1',
            'name' => 'T', 'type' => 'teleport',
        ])->assertStatus(422);
    }

    public function test_location_store_into_foreign_warehouse_returns_403(): void
    {
        $user = $this->user($this->warehouseA->id, ['manage-location']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/locations', [
            'warehouse_id' => $this->warehouseB->id, 'code' => 'X-1', 'name' => 'X',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('locations', ['code' => 'X-1']);
    }

    public function test_location_update_ignores_warehouse_move_and_status(): void
    {
        $location = Location::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'M-1', 'name' => 'M',
        ]);

        $this->actingAs($this->manager(), 'sanctum')->putJson(
            "/api/v1/admin/locations/{$location->id}",
            ['name' => 'M Renamed', 'warehouse_id' => $this->warehouseB->id, 'status' => 'inactive']
        )->assertStatus(200)->assertJsonFragment(['name' => 'M Renamed']);

        $fresh = $location->fresh();
        $this->assertSame((int) $this->warehouseA->id, (int) $fresh->warehouse_id);
        $this->assertSame('active', $fresh->status);
    }

    public function test_location_update_cross_warehouse_returns_403(): void
    {
        $location = Location::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'N-1', 'name' => 'N',
        ]);
        $user = $this->user($this->warehouseB->id, ['manage-location']);

        $this->actingAs($user, 'sanctum')->putJson(
            "/api/v1/admin/locations/{$location->id}",
            ['name' => 'Hijack']
        )->assertStatus(403);
    }

    public function test_location_activate_deactivate_cycle(): void
    {
        $location = Location::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'C-1', 'name' => 'C',
        ]);
        $manager = $this->manager();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/locations/{$location->id}/deactivate")
            ->assertStatus(200)
            ->assertJsonFragment(['status' => 'inactive']);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/locations/{$location->id}/activate")
            ->assertStatus(200)
            ->assertJsonFragment(['status' => 'active']);
    }
}

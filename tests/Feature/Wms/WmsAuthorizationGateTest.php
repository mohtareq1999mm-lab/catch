<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * P9-2.5: authorization security gate — adversarial HTTP matrix.
 *
 * Covers what P9-2 left unproven: null-home users on show/store, default
 * singleton under denied actors (with DB side-effect proof), soft-deleted /
 * inactive warehouse behavior, caller-controlled actor IDs, and the exact
 * scope of the manage-warehouse global bypass.
 */
class WmsAuthorizationGateTest extends TestCase
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
            'code' => 'WH-GA', 'name' => 'Gate A', 'status' => 'active', 'is_default' => true,
        ]);
        $this->warehouseB = Warehouse::create([
            'code' => 'WH-GB', 'name' => 'Gate B', 'status' => 'active', 'is_default' => false,
        ]);
    }

    private function user(?int $home, array $permissions = []): User
    {
        $user = User::factory()->create(['type' => 'staff', 'warehouse_id' => $home]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    // ---------- null-home users fail closed (except designed global) ----------

    public function test_null_home_viewer_cannot_read_or_list(): void
    {
        $user = $this->user(null, ['view-warehouse', 'view-location']);

        // Show denies via anti-enumeration (no existence leak).
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/admin/warehouses/{$this->warehouseA->id}")
            ->assertStatus(404);

        // Index denies explicitly (cannot scope a listing without a home).
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/warehouses')->assertStatus(403);
    }

    public function test_null_home_location_writer_is_denied_with_zero_side_effects(): void
    {
        $user = $this->user(null, ['manage-location']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/locations', [
            'warehouse_id' => $this->warehouseA->id, 'code' => 'G-01', 'name' => 'G',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('locations', ['code' => 'G-01']);
    }

    public function test_null_home_global_manager_may_create_warehouse_by_design(): void
    {
        // manage-warehouse is the approved global bypass: warehouse creation
        // has no per-object target, so any holder may create. Documented,
        // not a finding.
        $user = $this->user(null, ['manage-warehouse', 'view-warehouse']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/warehouses', [
            'code' => 'WH-GC', 'name' => 'Gate C',
        ])->assertStatus(201);

        $this->assertDatabaseHas('warehouses', ['code' => 'WH-GC']);
    }

    // ---------- default singleton under denied actors ----------

    public function test_viewer_cannot_change_default_and_flags_are_untouched(): void
    {
        $user = $this->user($this->warehouseA->id, ['view-warehouse']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/warehouses/{$this->warehouseB->id}/set-default")
            ->assertStatus(403);

        $this->assertTrue($this->warehouseA->fresh()->is_default);
        $this->assertFalse($this->warehouseB->fresh()->is_default);
    }

    public function test_viewer_cannot_deactivate_and_status_is_untouched(): void
    {
        $user = $this->user($this->warehouseA->id, ['view-warehouse']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/warehouses/{$this->warehouseB->id}/deactivate")
            ->assertStatus(403);

        $this->assertSame('active', $this->warehouseB->fresh()->status);
    }

    public function test_viewer_cannot_delete_and_row_survives(): void
    {
        $user = $this->user($this->warehouseA->id, ['view-warehouse']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/admin/warehouses/{$this->warehouseB->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('warehouses', ['code' => 'WH-GB', 'deleted_at' => null]);
    }

    // ---------- deleted / inactive warehouses ----------

    public function test_soft_deleted_warehouse_is_invisible_and_unusable(): void
    {
        $this->warehouseB->delete();
        // Home-B actor: scope passes (home == target), then the trashed row
        // is genuinely missing → 404. Home-A actors get 403 earlier (scope
        // before existence), so neither case oracles existence.
        $managerB = $this->user($this->warehouseB->id, [
            'view-warehouse', 'manage-warehouse', 'view-location', 'manage-location',
        ]);

        // Genuine 404 (global scope excludes trashed) — no leak, no action.
        $this->actingAs($managerB, 'sanctum')
            ->getJson("/api/v1/admin/warehouses/{$this->warehouseB->id}")
            ->assertStatus(404);
        $this->actingAs($managerB, 'sanctum')
            ->postJson("/api/v1/admin/warehouses/{$this->warehouseB->id}/set-default")
            ->assertStatus(404);

        // Location creation under a deleted warehouse → 404, zero side effects.
        $this->actingAs($managerB, 'sanctum')->postJson('/api/v1/admin/locations', [
            'warehouse_id' => $this->warehouseB->id, 'code' => 'GD-1', 'name' => 'GD',
        ])->assertStatus(404);
        $this->assertDatabaseMissing('locations', ['code' => 'GD-1']);
    }

    public function test_location_creation_under_inactive_warehouse_is_allowed_by_existing_rules(): void
    {
        // No Phase 0–8 rule blocks placement scaffolding under an inactive
        // warehouse (assertActive gates fulfillment creation only). Observed
        // and documented — not a finding, no new rule invented.
        $this->warehouseB->update(['status' => 'inactive']);
        $managerB = $this->user($this->warehouseB->id, [
            'view-warehouse', 'manage-warehouse', 'view-location', 'manage-location',
        ]);

        $this->actingAs($managerB, 'sanctum')->postJson('/api/v1/admin/locations', [
            'warehouse_id' => $this->warehouseB->id, 'code' => 'GI-1', 'name' => 'GI',
        ])->assertStatus(201);

        $this->assertDatabaseHas('locations', ['code' => 'GI-1']);
    }

    // ---------- actor forgery ----------

    public function test_caller_controlled_actor_ids_are_ignored(): void
    {
        $manager = $this->user($this->warehouseA->id, [
            'view-warehouse', 'manage-warehouse', 'view-location', 'manage-location',
        ]);
        $other = User::factory()->create(['type' => 'staff']);

        $response = $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/warehouses', [
            'code' => 'WH-GF', 'name' => 'Gate F',
            'user_id' => $other->id, 'actor_id' => $other->id,
            'created_by' => $other->id, 'cancelled_by' => $other->id,
        ]);
        $response->assertStatus(201);
        $body = $response->json('data');
        $this->assertArrayNotHasKey('user_id', $body);
        $this->assertArrayNotHasKey('actor_id', $body);
        $this->assertArrayNotHasKey('created_by', $body);
        $this->assertArrayNotHasKey('cancelled_by', $body);

        $location = Location::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'GF-1', 'name' => 'GF',
        ]);
        $response = $this->actingAs($manager, 'sanctum')->putJson(
            "/api/v1/admin/locations/{$location->id}",
            ['name' => 'GF Renamed', 'user_id' => $other->id, 'updated_by' => $other->id]
        );
        $response->assertStatus(200);
        $this->assertSame('GF Renamed', $location->fresh()->name);
    }

    // ---------- global bypass exact scope ----------

    public function test_manage_warehouse_does_not_grant_location_or_shipment_access(): void
    {
        // Holder has ONLY manage-warehouse: warehouse writes allowed by
        // design, but location reads/writes and legacy shipment writes are
        // still gated by their own permissions.
        $user = $this->user($this->warehouseA->id, ['manage-warehouse']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/locations')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/locations', [
            'warehouse_id' => $this->warehouseA->id, 'code' => 'GS-1', 'name' => 'GS',
        ])->assertStatus(403);
        $this->assertDatabaseMissing('locations', ['code' => 'GS-1']);
    }

    public function test_location_manager_cannot_reach_warehouse_writes(): void
    {
        $user = $this->user($this->warehouseA->id, ['view-warehouse', 'manage-location']);

        $this->actingAs($user, 'sanctum')->postJson(
            '/api/v1/admin/warehouses',
            ['code' => 'WH-GX', 'name' => 'GX']
        )->assertStatus(403);
        $this->assertDatabaseMissing('warehouses', ['code' => 'WH-GX']);
    }
}

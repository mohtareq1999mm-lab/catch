<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Warehouse;
use App\Services\Warehouse\WarehouseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WarehouseUserAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['view-warehouse', 'manage-warehouse', 'view-shipment'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouseA = Warehouse::create([
            'code' => 'WH-A', 'name' => 'Warehouse Alpha', 'status' => 'active', 'is_default' => true,
        ]);
        $this->warehouseB = Warehouse::create([
            'code' => 'WH-B', 'name' => 'Warehouse Beta', 'status' => 'active', 'is_default' => false,
        ]);
    }

    private function createUser(?int $home = null, array $permissions = []): User
    {
        $user = User::factory()->create(['type' => 'staff', 'warehouse_id' => $home]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    public function test_guest_cannot_assign_or_view_warehouse_users(): void
    {
        $this->getJson("/api/v1/admin/warehouses/{$this->warehouseA->id}/users")
            ->assertStatus(401);

        $this->postJson("/api/v1/admin/warehouses/{$this->warehouseA->id}/users", ['user_ids' => [1]])
            ->assertStatus(401);
    }

    public function test_staff_without_manage_permission_cannot_assign_users(): void
    {
        $staff = $this->createUser($this->warehouseA->id, ['view-warehouse']);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/admin/warehouses/{$this->warehouseA->id}/users", ['user_ids' => [1]])
            ->assertStatus(403);
    }

    public function test_manager_can_bulk_assign_users_to_warehouse(): void
    {
        $manager = $this->createUser($this->warehouseA->id, ['manage-warehouse', 'view-warehouse']);

        $u1 = $this->createUser();
        $u2 = $this->createUser();
        $u3 = $this->createUser();

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/warehouses/{$this->warehouseA->id}/users", [
                'user_ids' => [$u1->id, $u2->id],
                'mode' => 'attach',
            ]);

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.count'));

        $this->assertDatabaseHas('warehouse_users', [
            'warehouse_id' => $this->warehouseA->id,
            'user_id' => $u1->id,
        ]);
        $this->assertDatabaseHas('warehouse_users', [
            'warehouse_id' => $this->warehouseA->id,
            'user_id' => $u2->id,
        ]);

        // List assigned users
        $listResponse = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/admin/warehouses/{$this->warehouseA->id}/users");

        $listResponse->assertStatus(200);
        $this->assertCount(2, $listResponse->json('data'));
        $this->assertSame(
            'Warehouse users fetched successfully.',
            $listResponse->json('message')
        );
        $item = $listResponse->json('data.0');
        // Shape pin: selected columns only, plus pivot metadata (probe-verified);
        // key ORDER is deliberately not asserted.
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'email', 'type', 'email_verified', 'pivot'],
            array_keys($item)
        );
        $this->assertEqualsCanonicalizing(
            ['warehouse_id', 'user_id', 'is_primary', 'created_at', 'updated_at'],
            array_keys($item['pivot'])
        );

        // Idempotent re-attach does not duplicate
        $reAttach = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/warehouses/{$this->warehouseA->id}/users", [
                'user_ids' => [$u1->id, $u3->id],
                'mode' => 'attach',
            ]);
        $reAttach->assertStatus(200);
        $this->assertSame(3, $this->warehouseA->users()->count());
        // Shape pin: service result contract, order-agnostic.
        $this->assertEqualsCanonicalizing(
            ['warehouse_id', 'user_ids', 'count', 'mode'],
            array_keys($reAttach->json('data'))
        );
    }

    public function test_manager_can_sync_and_detach_users(): void
    {
        $manager = $this->createUser($this->warehouseA->id, ['manage-warehouse', 'view-warehouse']);

        $u1 = $this->createUser();
        $u2 = $this->createUser();
        $u3 = $this->createUser();

        // Initial attach
        $this->warehouseA->users()->attach([$u1->id, $u2->id]);

        // Sync to only u2 and u3
        $syncResponse = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/warehouses/{$this->warehouseA->id}/users", [
                'user_ids' => [$u2->id, $u3->id],
                'mode' => 'sync',
            ]);

        $syncResponse->assertStatus(200);
        $this->assertDatabaseMissing('warehouse_users', [
            'warehouse_id' => $this->warehouseA->id,
            'user_id' => $u1->id,
        ]);
        $this->assertDatabaseHas('warehouse_users', [
            'warehouse_id' => $this->warehouseA->id,
            'user_id' => $u3->id,
        ]);

        // Detach u3
        $detachResponse = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/warehouses/{$this->warehouseA->id}/users", [
                'user_ids' => [$u3->id],
                'mode' => 'detach',
            ]);

        $detachResponse->assertStatus(200);
        $this->assertDatabaseMissing('warehouse_users', [
            'warehouse_id' => $this->warehouseA->id,
            'user_id' => $u3->id,
        ]);
        $this->assertDatabaseHas('warehouse_users', [
            'warehouse_id' => $this->warehouseA->id,
            'user_id' => $u2->id,
        ]);
    }

    public function test_user_assigned_via_pivot_is_authorized_by_warehouse_access(): void
    {
        // User has NO home warehouse (warehouse_id = null)
        $worker = $this->createUser(null, ['view-shipment']);

        $access = app(WarehouseAccess::class);

        // Before assignment: not allowed
        $this->assertFalse($access->allows($worker, $this->warehouseA->id, 'view-shipment'));

        // Assign worker to Warehouse A via pivot table
        $this->warehouseA->users()->attach($worker->id);

        // After assignment: allowed via pivot relationship!
        $this->assertTrue($access->allows($worker->fresh(), $this->warehouseA->id, 'view-shipment'));

        // Still not allowed on Warehouse B
        $this->assertFalse($access->allows($worker->fresh(), $this->warehouseB->id, 'view-shipment'));
    }

    public function test_validation_rejects_empty_or_invalid_users(): void
    {
        $manager = $this->createUser($this->warehouseA->id, ['manage-warehouse', 'view-warehouse']);

        // Empty array
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/warehouses/{$this->warehouseA->id}/users", [
                'user_ids' => [],
            ])->assertStatus(422);

        // Non-existent user id
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/warehouses/{$this->warehouseA->id}/users", [
                'user_ids' => [999999],
            ])->assertStatus(422);

        // Invalid mode
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/warehouses/{$this->warehouseA->id}/users", [
                'user_ids' => [$manager->id],
                'mode' => 'invalid_mode',
            ])->assertStatus(422);
    }
}

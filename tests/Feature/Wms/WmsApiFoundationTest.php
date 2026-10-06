<?php

namespace Tests\Feature\Wms;

use App\Http\Controllers\Api\Admin\Wms\WmsAdminController;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\TestCase;

/**
 * P9-1: API foundation contract.
 *
 * Proves the error/authorization primitives every later WMS endpoint
 * relies on: 401 unauthenticated, 403 permission/scope denial, 404
 * anti-enumeration reads, 409/422 domain-error mapping, server-derived
 * actor identity, and fail-closed warehouse scope (same/different/null).
 */
class WmsApiFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->warehouseA = Warehouse::create([
            'code' => 'WH-F1', 'name' => 'Foundation A', 'status' => 'active', 'is_default' => true,
        ]);
        $this->warehouseB = Warehouse::create([
            'code' => 'WH-F2', 'name' => 'Foundation B', 'status' => 'active', 'is_default' => false,
        ]);

        Permission::firstOrCreate(['name' => 'view-fulfillment', 'guard_name' => 'api']);
    }

    private function probe(): WmsAdminController
    {
        return new class extends WmsAdminController
        {
            public function scope(?int $warehouseId, string $permission, bool $asNotFound = false): void
            {
                $this->authorizeWarehouseScope($warehouseId, $permission, $asNotFound);
            }

            public function who(): int
            {
                return $this->actorId();
            }

            public function boomConflict(string $message): never
            {
                $this->conflict($message);
            }

            public function boomUnprocessable(string $message): never
            {
                $this->unprocessable($message);
            }
        };
    }

    private function userWithHome(?int $warehouseId, array $permissions = []): User
    {
        $user = User::factory()->create(['type' => 'staff', 'warehouse_id' => $warehouseId]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    public function test_unauthenticated_wms_route_returns_401(): void
    {
        $this->getJson('/api/v1/shipments')->assertStatus(401)->assertJson(['status' => false]);
    }

    public function test_authenticated_user_without_permission_returns_403(): void
    {
        $user = $this->userWithHome($this->warehouseA->id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/shipments')
            ->assertStatus(403)
            ->assertJson(['status' => false]);
    }

    public function test_same_warehouse_with_permission_is_allowed(): void
    {
        $user = $this->userWithHome($this->warehouseA->id, ['view-fulfillment']);
        $this->actingAs($user, 'sanctum');

        $this->probe()->scope($this->warehouseA->id, 'view-fulfillment');
        $this->assertTrue(true);
    }

    public function test_cross_warehouse_write_is_forbidden_403(): void
    {
        $user = $this->userWithHome($this->warehouseA->id, ['view-fulfillment']);
        $this->actingAs($user, 'sanctum');

        $this->expectException(AuthorizationException::class);
        $this->probe()->scope($this->warehouseB->id, 'view-fulfillment');
    }

    public function test_cross_warehouse_read_uses_anti_enumeration_404(): void
    {
        $user = $this->userWithHome($this->warehouseA->id, ['view-fulfillment']);
        $this->actingAs($user, 'sanctum');

        $this->expectException(NotFoundHttpException::class);
        $this->probe()->scope($this->warehouseB->id, 'view-fulfillment', true);
    }

    public function test_null_home_warehouse_fails_closed(): void
    {
        $user = $this->userWithHome(null, ['view-fulfillment']);
        $this->actingAs($user, 'sanctum');

        $this->expectException(AuthorizationException::class);
        $this->probe()->scope($this->warehouseA->id, 'view-fulfillment');
    }

    public function test_null_target_warehouse_fails_closed(): void
    {
        $user = $this->userWithHome($this->warehouseA->id, ['view-fulfillment']);
        $this->actingAs($user, 'sanctum');

        $this->expectException(AuthorizationException::class);
        $this->probe()->scope(null, 'view-fulfillment');
    }

    public function test_missing_permission_denies_even_same_warehouse(): void
    {
        $user = $this->userWithHome($this->warehouseA->id);
        $this->actingAs($user, 'sanctum');

        $this->expectException(AuthorizationException::class);
        $this->probe()->scope($this->warehouseA->id, 'view-fulfillment');
    }

    public function test_actor_identity_is_server_derived(): void
    {
        $user = $this->userWithHome($this->warehouseA->id);
        $this->actingAs($user, 'sanctum');

        $this->assertSame($user->id, $this->probe()->who());
    }

    public function test_domain_helpers_map_to_409_and_422(): void
    {
        $probe = $this->probe();

        try {
            $probe->boomConflict('claimed by other');
            $this->fail('conflict() must throw');
        } catch (ConflictHttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        try {
            $probe->boomUnprocessable('illegal transition');
            $this->fail('unprocessable() must throw');
        } catch (UnprocessableEntityHttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\Shipping\ShipmentExceptionStatus;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentException;
use App\Services\Shipment\ShipmentExceptionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ShipmentExceptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_exception_moves_through_allowed_lifecycle_states_without_mutating_shipment(): void
    {
        [$shipment, $warehouse] = $this->createShipmentWithWarehouse('pending');
        $order = $shipment->order;
        $orderBefore = $order->fresh()->only(['status', 'payment_status', 'fulfillment_status']);
        $exception = ShipmentException::create([
            'shipment_id' => $shipment->id,
            'type' => 'UNKNOWN_STATUS',
            'severity' => 'high',
            'status' => ShipmentExceptionStatus::OPEN->value,
            'notes' => 'diagnostic: carrier timeout at 10:00',
            'opened_at' => now(),
        ]);

        $service = app(ShipmentExceptionService::class);
        $investigator = $this->createWarehouseUser('investigator', $warehouse);

        $investigating = $service->markInvestigating($exception, $investigator, 'checking carrier');
        $this->assertSame(ShipmentExceptionStatus::INVESTIGATING->value, $investigating->status);
        $this->assertStringContainsString('diagnostic: carrier timeout at 10:00', $investigating->notes);
        $this->assertStringContainsString('checking carrier', $investigating->notes);
        $this->assertSame('pending', $shipment->fresh()->status);
        $this->assertSame($orderBefore, $order->fresh()->only(array_keys($orderBefore)));

        $resolved = $service->resolve($investigating, $investigator, 'carrier confirmed');
        $this->assertSame(ShipmentExceptionStatus::RESOLVED->value, $resolved->status);
        $this->assertStringContainsString('diagnostic: carrier timeout at 10:00', $resolved->notes);
        $this->assertStringContainsString('checking carrier', $resolved->notes);
        $this->assertStringContainsString('carrier confirmed', $resolved->notes);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame($investigator->id, $resolved->resolved_by);
        $this->assertSame('pending', $shipment->fresh()->status);
        $this->assertSame($orderBefore, $order->fresh()->only(array_keys($orderBefore)));

        $dismissedException = ShipmentException::create([
            'shipment_id' => $shipment->id,
            'type' => 'MODE_MISMATCH',
            'severity' => 'high',
            'status' => ShipmentExceptionStatus::OPEN->value,
            'notes' => 'diagnostic: mode evidence attached',
            'opened_at' => now(),
        ]);

        $dismissed = $service->dismiss($dismissedException, $investigator, 'false positive');
        $this->assertSame(ShipmentExceptionStatus::DISMISSED->value, $dismissed->status);
        $this->assertStringContainsString('diagnostic: mode evidence attached', $dismissed->notes);
        $this->assertStringContainsString('false positive', $dismissed->notes);
        $this->assertNotNull($dismissed->resolved_at);
        $this->assertSame($investigator->id, $dismissed->resolved_by);
        $this->assertSame('pending', $shipment->fresh()->status);
        $this->assertSame($orderBefore, $order->fresh()->only(array_keys($orderBefore)));
    }

    public function test_exception_investigation_is_warehouse_scoped(): void
    {
        [$shipment, $warehouseA] = $this->createShipmentWithWarehouse('pending');
        $warehouseB = Warehouse::create(['name' => 'B', 'code' => 'WH-B', 'status' => 'active']);
        $exception = ShipmentException::create([
            'shipment_id' => $shipment->id,
            'type' => 'UNKNOWN_STATUS',
            'severity' => 'high',
            'status' => ShipmentExceptionStatus::OPEN->value,
            'notes' => 'opened',
            'opened_at' => now(),
        ]);

        $service = app(ShipmentExceptionService::class);
        $otherWarehouseUser = $this->createWarehouseUser('other', $warehouseB);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->markInvestigating($exception, $otherWarehouseUser, 'checking');
    }

    public function test_unmatched_exception_can_be_investigated_by_global_warehouse_manager(): void
    {
        $exception = ShipmentException::create([
            'shipment_id' => null,
            'type' => 'UNMATCHED_SHIPMENT',
            'severity' => 'high',
            'status' => ShipmentExceptionStatus::OPEN->value,
            'notes' => 'opened',
            'opened_at' => now(),
        ]);

        $service = app(ShipmentExceptionService::class);
        $manager = $this->createGlobalUser('manager');

        $investigating = $service->markInvestigating($exception, $manager, 'reviewing inbox');

        $this->assertSame(ShipmentExceptionStatus::INVESTIGATING->value, $investigating->status);
        $this->assertSame($manager->id, $investigating->owner_id);
    }

    public function test_unmatched_exception_is_hidden_from_warehouse_scoped_actor(): void
    {
        $warehouse = Warehouse::create(['name' => 'Main', 'code' => 'WH-MAIN-'.Str::random(4), 'status' => 'active']);
        $exception = ShipmentException::create([
            'shipment_id' => null,
            'type' => 'UNMATCHED_SHIPMENT',
            'severity' => 'high',
            'status' => ShipmentExceptionStatus::OPEN->value,
            'notes' => 'opened',
            'opened_at' => now(),
        ]);

        $service = app(ShipmentExceptionService::class);
        $scopedUser = $this->createWarehouseUser('scoped', $warehouse);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->markInvestigating($exception, $scopedUser, 'reviewing inbox');
    }

    public function test_meaningful_exception_actions_require_notes(): void
    {
        [$shipment, $warehouse] = $this->createShipmentWithWarehouse('pending');
        $exception = ShipmentException::create([
            'shipment_id' => $shipment->id,
            'type' => 'UNKNOWN_STATUS',
            'severity' => 'high',
            'status' => ShipmentExceptionStatus::OPEN->value,
            'notes' => 'opened',
            'opened_at' => now(),
        ]);

        $service = app(ShipmentExceptionService::class);
        $user = $this->createWarehouseUser('investigator', $warehouse);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Notes are required');
        $service->markInvestigating($exception, $user, '   ');
    }

    public function test_resolve_is_warehouse_scoped(): void
    {
        [$shipment] = $this->createShipmentWithWarehouse('pending');
        $otherWarehouse = Warehouse::create(['name' => 'B', 'code' => 'WH-B-'.Str::random(4), 'status' => 'active']);
        $exception = ShipmentException::create([
            'shipment_id' => $shipment->id,
            'type' => 'UNKNOWN_STATUS',
            'severity' => 'high',
            'status' => ShipmentExceptionStatus::OPEN->value,
            'notes' => 'diagnostic',
            'opened_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(ShipmentExceptionService::class)->resolve($exception, $this->createWarehouseUser('resolve-other', $otherWarehouse), 'wrong warehouse');
    }

    public function test_dismiss_is_warehouse_scoped(): void
    {
        [$shipment] = $this->createShipmentWithWarehouse('pending');
        $otherWarehouse = Warehouse::create(['name' => 'C', 'code' => 'WH-C-'.Str::random(4), 'status' => 'active']);
        $exception = ShipmentException::create([
            'shipment_id' => $shipment->id,
            'type' => 'MODE_MISMATCH',
            'severity' => 'high',
            'status' => ShipmentExceptionStatus::OPEN->value,
            'notes' => 'diagnostic',
            'opened_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(ShipmentExceptionService::class)->dismiss($exception, $this->createWarehouseUser('dismiss-other', $otherWarehouse), 'wrong warehouse');
    }

    public function test_numeric_actor_id_is_not_accepted_as_authenticated_actor(): void
    {
        [$shipment, $warehouse] = $this->createShipmentWithWarehouse('pending');
        $user = $this->createWarehouseUser('numeric-actor', $warehouse);
        $exception = ShipmentException::create([
            'shipment_id' => $shipment->id,
            'type' => 'UNKNOWN_STATUS',
            'severity' => 'high',
            'status' => ShipmentExceptionStatus::OPEN->value,
            'notes' => 'diagnostic',
            'opened_at' => now(),
        ]);

        $this->expectException(\TypeError::class);
        app(ShipmentExceptionService::class)->markInvestigating($exception, $user->id, 'checking');
    }

    public function test_actor_object_without_identity_is_rejected(): void
    {
        [$shipment] = $this->createShipmentWithWarehouse('pending');
        $exception = ShipmentException::create([
            'shipment_id' => $shipment->id,
            'type' => 'UNKNOWN_STATUS',
            'severity' => 'high',
            'status' => ShipmentExceptionStatus::OPEN->value,
            'notes' => 'diagnostic',
            'opened_at' => now(),
        ]);

        $anonymous = Mockery::mock(Authenticatable::class);
        $anonymous->shouldReceive('getAuthIdentifier')->andReturnNull();

        $this->expectException(AuthorizationException::class);
        app(ShipmentExceptionService::class)->markInvestigating($exception, $anonymous, 'checking');
    }

    private function createWarehouseUser(string $prefix, Warehouse $warehouse): User
    {
        $user = $this->createUser($prefix);
        $user->warehouse_id = $warehouse->id;
        $user->save();
        $perm = Permission::findOrCreate('update-shipment', 'api');
        $user->givePermissionTo($perm);
        return $user;
    }

    private function createGlobalUser(string $prefix): User
    {
        $user = $this->createUser($prefix);
        foreach (['update-shipment', 'manage-warehouse'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'api'));
        }

        return $user;
    }

    private function createUser(string $prefix): User
    {
        return User::create([
            'name' => ucfirst($prefix),
            'email' => $prefix.'-'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('password'),
        ]);
    }

    /** @return array{0:Shipment,1:Warehouse} */
    private function createShipmentWithWarehouse(string $status): array
    {
        $warehouse = Warehouse::create(['name' => 'Main', 'code' => 'WH-MAIN-'.Str::random(4), 'status' => 'active']);
        $user = $this->createUser('customer');
        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Exception Customer',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'price' => 100,
            'total_price' => 100,
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);
        $fulfillment = Fulfillment::create([
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'pending',
        ]);
        $shipment = Shipment::create([
            'order_id' => $order->id,
            'fulfillment_id' => $fulfillment->id,
            'status' => $status,
        ]);

        return [$shipment, $warehouse];
    }
}

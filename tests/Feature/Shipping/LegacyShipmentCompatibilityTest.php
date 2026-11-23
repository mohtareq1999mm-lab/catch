<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Shipment;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * #166: Legacy shipment endpoints are compatibility shims. Their response
 * shapes remain stable for current consumers while writes delegate to the
 * canonical ShipmentService authority.
 */
class LegacyShipmentCompatibilityTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesTestTables;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->createAllTestTables();

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'broadcasting.connections.pusher.driver' => 'log',
            'shipping.default_provider' => 'internal',
            'shipping.providers.internal.service_level' => 'standard',
        ]);

        $this->admin = User::factory()->create(['type' => 'admin', 'is_active' => true]);

        foreach ([
            Permission::VIEW_SHIPMENTS,
            Permission::VIEW_SHIPMENT,
            Permission::CREATE_SHIPMENT,
            Permission::UPDATE_SHIPMENT,
            Permission::ORDERS_SHIPMENT_SHOW,
            Permission::ORDERS_SHIPMENT_UPDATE_STATUS,
        ] as $name) {
            $permission = PermissionModel::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
            $this->admin->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_legacy_shipments_index_response_shape_is_unchanged(): void
    {
        $shipment = $this->createShipment();
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/shipments');

        $response->assertOk();
        $response->assertJsonStructure([
            'status',
            'message',
            'success',
            'data' => [
                '*' => $this->shipmentResourceKeys(),
            ],
        ]);
        $this->assertSame(true, $response->json('success'));
        $this->assertSame(200, $response->json('status'));
        $this->assertSame($shipment->id, $response->json('data.0.id'));
    }

    public function test_legacy_shipments_show_response_shape_is_unchanged(): void
    {
        $shipment = $this->createShipment(['courier' => 'legacy-courier', 'tracking_number' => 'TRK-COMPAT-1']);
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/shipments/' . $shipment->id);

        $response->assertOk();
        $this->assertLegacyShipmentEnvelope($response->json(), $shipment);
    }

    public function test_legacy_shipments_show_by_uuid_response_shape_is_unchanged(): void
    {
        $shipment = $this->createShipment();
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/shipments/uuid/' . $shipment->uuid);

        $response->assertOk();
        $this->assertLegacyShipmentEnvelope($response->json(), $shipment);
    }

    public function test_legacy_shipments_store_response_shape_is_unchanged_and_delegates(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/shipments', [
            'order_id' => $order->id,
            'tracking_number' => 'TRK-CREATE-1',
            'courier' => 'DHL',
            'shipping_method' => 'standard',
            'shipping_cost' => 25.50,
            'currency' => 'EGP',
            'notes' => 'compat create',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'status',
            'message',
            'success',
            'data' => $this->shipmentResourceKeys(),
        ]);
        $this->assertSame(true, $response->json('success'));
        $this->assertSame(201, $response->json('status'));
        $this->assertSame($order->id, $response->json('data.order_id'));
        $this->assertSame('TRK-CREATE-1', $response->json('data.tracking_number'));
        $this->assertSame('compat create', $response->json('data.notes'));

        $shipment = Shipment::where('tracking_number', 'TRK-CREATE-1')->firstOrFail();
        $this->assertSame('pending', $shipment->status);
    }

    public function test_legacy_shipments_update_status_response_shape_is_unchanged_and_delegates(): void
    {
        $shipment = $this->createShipment(['status' => 'pending']);
        Sanctum::actingAs($this->admin);

        $response = $this->putJson('/api/v1/shipments/' . $shipment->id . '/status', [
            'status' => 'label_created',
            'notes' => 'compat status',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'status',
            'message',
            'success',
            'data' => $this->shipmentResourceKeys(),
        ]);
        $this->assertSame(true, $response->json('success'));
        $this->assertSame(200, $response->json('status'));
        $this->assertSame('label_created', $response->json('data.status'));
        $this->assertSame('label_created', $shipment->fresh()->status);
    }

    public function test_legacy_shipments_update_response_shape_is_unchanged_and_delegates(): void
    {
        $shipment = $this->createShipment(['courier' => 'old']);
        Sanctum::actingAs($this->admin);

        $response = $this->putJson('/api/v1/shipments/' . $shipment->id, [
            'courier' => 'new-courier',
            'notes' => 'compat update',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'status',
            'message',
            'success',
            'data' => $this->shipmentResourceKeys(),
        ]);
        $this->assertSame(true, $response->json('success'));
        $this->assertSame(200, $response->json('status'));
        $this->assertSame('new-courier', $response->json('data.courier'));
        $this->assertSame('new-courier', $shipment->fresh()->courier);
    }

    public function test_legacy_shipments_status_write_rejects_direct_status_mutation_contract(): void
    {
        $shipment = $this->createShipment(['status' => 'pending']);
        Sanctum::actingAs($this->admin);

        // Invalid transition must be rejected by canonical authority; no direct mutation.
        $response = $this->putJson('/api/v1/shipments/' . $shipment->id . '/status', [
            'status' => 'delivered',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['status', 'message', 'success']);
        $this->assertSame(false, $response->json('success'));
        $this->assertSame('pending', $shipment->fresh()->status);
    }

    public function test_legacy_admin_order_shipment_show_response_shape_is_unchanged(): void
    {
        $order = $this->createOrder([
            'shipment_status' => 'in_transit',
            'tracking_number' => 'TRK-ADMIN-1',
            'courier_name' => 'Bosta',
        ]);
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/admin/orders/' . $order->id . '/shipment');

        $response->assertOk();
        $response->assertExactJson([
            'success' => true,
            'data' => [
                'order_id' => $order->id,
                'shipment_status' => 'in_transit',
                'tracking_number' => 'TRK-ADMIN-1',
                'courier_name' => 'Bosta',
                'estimated_delivery_at' => $order->estimated_delivery_at?->toIso8601String(),
                'actual_delivery_at' => $order->actual_delivery_at?->toIso8601String(),
            ],
        ]);
    }

    public function test_legacy_admin_order_shipment_update_response_shape_is_unchanged_and_delegates(): void
    {
        $order = $this->createOrder(['shipment_status' => 'pending']);
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/admin/orders/' . $order->id . '/shipment/update-status', [
            'status' => 'in_transit',
            'tracking_number' => 'TRK-ADMIN-2',
            'courier_name' => 'Aramex',
        ]);

        $response->assertOk();
        $response->assertExactJson([
            'success' => true,
            'message' => __('message.MESSAGE.SHIPMENT_STATUS_UPDATED_SUCCESSFULLY'),
            'data' => [
                'order_id' => $order->id,
                'shipment_status' => 'in_transit',
                'tracking_number' => 'TRK-ADMIN-2',
            ],
        ]);

        $fresh = $order->fresh();
        $this->assertSame('in_transit', $fresh->shipment_status);
        $this->assertSame('TRK-ADMIN-2', $fresh->tracking_number);
        $this->assertDatabaseHas('order_tracking_events', [
            'order_id' => $order->id,
            'event_type' => 'shipment.status_changed',
            'new_status' => 'in_transit',
            'source' => 'admin',
        ]);
    }

    public function test_legacy_admin_order_shipment_not_found_shape_is_unchanged(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/admin/orders/99999999/shipment');

        $response->assertStatus(404);
        $response->assertExactJson([
            'success' => false,
            'message' => __('message.ERROR.ORDER_NOT_FOUND'),
        ]);
    }

    public function test_legacy_write_paths_delegate_to_shipment_service(): void
    {
        $order = $this->createOrder(['shipment_status' => 'pending']);
        Sanctum::actingAs($this->admin);

        $calls = [];
        $mock = $this->mock(ShipmentService::class, function ($mock) use (&$calls, $order): void {
            $mock->shouldReceive('recordOrderStatusChange')
                ->once()
                ->withArgs(function (int $orderId, string $status, array $details) use (&$calls): bool {
                    $calls[] = compact('orderId', 'status', 'details');
                    return true;
                });
            $mock->shouldReceive('find')->andReturnUsing(fn () => Shipment::query()->firstOrFail());
        });
        app()->instance(ShipmentService::class, $mock);

        $this->postJson('/api/v1/admin/orders/' . $order->id . '/shipment/update-status', [
            'status' => 'in_transit',
            'tracking_number' => 'TRK-DELEGATE',
        ])->assertOk();

        $this->assertCount(1, $calls);
        $this->assertSame($order->id, $calls[0]['orderId']);
        $this->assertSame('in_transit', $calls[0]['status']);
    }

    private function shipmentResourceKeys(): array
    {
        return [
            'id',
            'uuid',
            'order_id',
            'tracking_number',
            'service_level',
            'courier',
            'status',
            'shipping_method',
            'shipping_cost',
            'currency',
            'origin_address',
            'destination_address',
            'items',
            'total_weight',
            'weight_unit',
            'notes',
            'shipped_at',
            'estimated_delivery_at',
            'delivered_at',
            'created_at',
            'updated_at',
        ];
    }

    /** @param array<string, mixed> $json */
    private function assertLegacyShipmentEnvelope(array $json, Shipment $shipment): void
    {
        $this->assertSame(200, $json['status']);
        $this->assertSame(true, $json['success']);
        $this->assertIsString($json['message']);
        $this->assertSame($this->shipmentResourceKeys(), array_keys($json['data']));
        $this->assertSame($shipment->id, $json['data']['id']);
        $this->assertSame($shipment->order_id, $json['data']['order_id']);
        $this->assertSame($shipment->tracking_number, $json['data']['tracking_number']);
        $this->assertSame($shipment->status, $json['data']['status']);
    }

    /** @param array<string, mixed> $overrides */
    private function createOrder(array $overrides = []): Order
    {
        $user = User::factory()->create();

        return Order::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Compat Customer',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'shipment_status' => $overrides['shipment_status'] ?? 'pending',
            'tracking_number' => $overrides['tracking_number'] ?? null,
            'courier_name' => $overrides['courier_name'] ?? null,
            'price' => 100,
            'total_price' => 100,
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function createShipment(array $overrides = []): Shipment
    {
        $order = $this->createOrder();

        return Shipment::create(array_merge([
            'order_id' => $order->id,
            'status' => 'pending',
            'tracking_number' => 'TRK-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'courier' => 'DHL',
            'shipping_method' => 'standard',
            'shipping_cost' => 10.00,
            'currency' => 'EGP',
            'origin_address' => ['city' => 'Warehouse'],
            'destination_address' => ['city' => 'Cairo'],
            'items' => [['sku' => 'A1', 'qty' => 1]],
            'total_weight' => 1.5,
            'weight_unit' => 'kg',
            'notes' => 'note',
            'provider_code' => 'internal',
            'provider_service_level' => 'standard',
        ], $overrides));
    }
}

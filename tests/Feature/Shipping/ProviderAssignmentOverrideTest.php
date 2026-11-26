<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Exceptions\CarrierConfigurationException;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Services\Shipment\ProviderAssignmentService;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * #148: provider choice is a deployment decision. Customers see a service
 * level; only an explicitly authorized administrator can override it, with an
 * actor + reason in the canonical activity log, and never after AWB creation.
 */
class ProviderAssignmentOverrideTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $admin;

    private User $reader;

    private User $warehouseScoped;

    private Warehouse $homeWarehouse;

    private Warehouse $otherWarehouse;

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
        config()->set('shipping.providers.alternate', [
            'adapter' => \App\Services\Shipment\Providers\NullCarrierGateway::class,
            'mode' => 'sandbox',
            'service_level' => 'express',
            'channels' => ['web'],
            'geographies' => ['EG'],
            'polling' => [
                'supported' => false,
                'cursor_type' => null,
                'pagination' => null,
                'overlap_window_seconds' => 0,
            ],
            'modes' => ['sandbox' => [
                'enabled' => true,
                'credentials' => ['api_key' => 'x'],
                'status_map' => ['version' => 1, 'statuses' => []],
            ]],
        ]);

        $this->homeWarehouse = Warehouse::create([
            'code' => 'WH-OVR-H-'.uniqid(),
            'name' => 'Override home warehouse',
            'status' => 'active',
            'is_default' => false,
        ]);
        $this->otherWarehouse = Warehouse::create([
            'code' => 'WH-OVR-O-'.uniqid(),
            'name' => 'Override other warehouse',
            'status' => 'active',
            'is_default' => false,
        ]);

        $this->admin = User::factory()->create(['type' => 'admin', 'is_active' => true]);
        $this->reader = User::factory()->create(['type' => 'admin', 'is_active' => true]);
        $this->warehouseScoped = User::factory()->create([
            'type' => 'staff',
            'is_active' => true,
            'warehouse_id' => $this->homeWarehouse->id,
        ]);

        $permission = PermissionModel::firstOrCreate([
            'name' => Permission::SHIPPING_PROVIDER_OVERRIDE,
            'guard_name' => 'api',
        ]);
        $view = PermissionModel::firstOrCreate([
            'name' => Permission::VIEW_SHIPMENT,
            'guard_name' => 'api',
        ]);
        $manage = PermissionModel::firstOrCreate([
            'name' => Permission::MANAGE_WAREHOUSE,
            'guard_name' => 'api',
        ]);
        $this->admin->syncPermissions([$permission, $view, $manage]);
        $this->reader->syncPermissions([$view]);
        $this->warehouseScoped->syncPermissions([$permission]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_default_provider_is_resolved_from_config_and_client_provider_input_is_ignored(): void
    {
        $this->assertSame(
            [
                'provider_code' => 'internal',
                'service_level' => 'standard',
                'mapping_version' => '1',
                'provider_mode' => 'internal',
            ],
            app(ProviderAssignmentService::class)->resolve(),
        );

        $shipment = $this->createShipment([
            'courier' => 'client-supplied-carrier',
            'provider_code' => 'alternate',
            'provider_service_level' => 'client-level',
            'provider_override' => true,
            'awb_created_at' => now(),
        ]);

        $this->assertSame('internal', $shipment->provider_code);
        $this->assertSame('standard', $shipment->provider_service_level);
        $this->assertFalse((bool) $shipment->provider_override);
        $this->assertNull($shipment->awb_created_at);
    }

    public function test_shipment_snapshots_the_validated_provider_mode_and_ignores_client_mode(): void
    {
        $assignment = app(ProviderAssignmentService::class)->resolve();

        $this->assertSame('internal', $assignment['provider_mode']);

        $shipment = $this->createShipment(['provider_mode' => 'production']);

        $this->assertSame('internal', $shipment->provider_mode);

        config(['shipping.providers.production_carrier' => $this->providerConfig('production')]);

        $this->assertSame(
            'production',
            app(ProviderAssignmentService::class)->forProvider('production_carrier')['provider_mode'],
        );
    }

    public function test_mixed_mode_configuration_is_refused_by_the_assignment_authority(): void
    {
        config(['shipping.providers.production_carrier' => $this->providerConfig('production')]);
        config(['shipping.providers.production_carrier.modes.sandbox' => [
            'enabled' => true,
            'credentials' => ['api_key' => 'sandbox-key'],
        ]]);

        try {
            app(ProviderAssignmentService::class)->forProvider('production_carrier');
            $this->fail('A provider with both modes active must not be assignable.');
        } catch (CarrierConfigurationException $exception) {
            $this->assertStringContainsString('mixed sandbox/production', $exception->getMessage());
        }
    }

    public function test_unconfigured_default_provider_fails_closed(): void
    {
        config(['shipping.default_provider' => 'ghost']);

        try {
            app(ProviderAssignmentService::class)->resolve();
            $this->fail('An unconfigured default provider must be rejected.');
        } catch (CarrierConfigurationException $exception) {
            $this->assertStringContainsString('ghost', $exception->getMessage());
        }
    }

    public function test_malformed_provider_code_is_rejected_by_the_assignment_authority(): void
    {
        $this->expectException(CarrierConfigurationException::class);

        app(ProviderAssignmentService::class)->forProvider('Bad Code');
    }

    public function test_audit_text_is_translated_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach ([
                'shipping.audit.override_reason_required',
                'shipping.audit.provider_overridden',
            ] as $key) {
                $this->assertNotSame($key, __($key));
            }
        }

        app()->setLocale('en');
    }

    public function test_override_to_a_disabled_provider_is_rejected_without_audit(): void
    {
        config(['shipping.providers.alternate.modes.sandbox.enabled' => false]);
        $shipment = $this->createShipment();

        Sanctum::actingAs($this->admin);
        $response = $this->postJson($this->overrideUrl($shipment), [
            'provider' => 'alternate',
            'reason' => 'configured but disabled',
        ]);

        $response->assertStatus(422);
        $this->assertSame(__('message.ERROR.SHIPPING_PROVIDER_UNAVAILABLE'), $response->json('message'));
        $this->assertSame(0, Activity::where('event', 'provider_override')->count());
        $this->assertSame('internal', $shipment->fresh()->provider_code);
    }

    public function test_legacy_shipment_response_exposes_service_level_and_courier_for_compatibility(): void
    {
        $shipment = $this->createShipment(['courier' => 'legacy-courier']);
        Sanctum::actingAs($this->reader);

        $response = $this->getJson('/api/v1/shipments/' . $shipment->id);

        $response->assertOk();
        // #166 will split the legacy compatibility shape from the customer
        // service-level representation; until then both fields are required.
        $this->assertSame('standard', $response->json('data.service_level'));
        $this->assertSame('legacy-courier', $response->json('data.courier'));
        $this->assertArrayNotHasKey('provider_code', $response->json('data'));
    }

    public function test_override_requires_permission_reason_and_writes_audited_actor(): void
    {
        $shipment = $this->createShipment();

        Sanctum::actingAs($this->reader);
        $this->postJson($this->overrideUrl($shipment), [
            'provider' => 'alternate',
            'reason' => 'carrier capacity incident',
        ])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson($this->overrideUrl($shipment), [
            'provider' => 'alternate',
        ])->assertStatus(422);

        $this->assertSame(0, Activity::where('event', 'provider_override')->count());
        $this->assertSame('internal', $shipment->fresh()->provider_code);

        $response = $this->postJson($this->overrideUrl($shipment), [
            'provider' => 'alternate',
            'reason' => 'carrier capacity incident',
        ]);

        $response->assertOk();
        $this->assertSame([
            'shipment_id' => $shipment->id,
            'provider_code' => 'alternate',
            'service_level' => 'express',
            'provider_override' => true,
        ], $response->json('data'));
        $this->assertSame(__('message.MESSAGE.SHIPPING_PROVIDER_OVERRIDDEN'), $response->json('message'));

        $updated = $shipment->fresh();
        $this->assertSame('alternate', $updated->provider_code);
        $this->assertSame('express', $updated->provider_service_level);
        $this->assertTrue((bool) $updated->provider_override);

        $activity = Activity::where('event', 'provider_override')->latest()->first();
        $this->assertNotNull($activity);
        $this->assertSame('shipping', $activity->log_name);
        $this->assertSame($this->admin->id, (int) $activity->causer_id);
        $this->assertSame(get_class($this->admin), $activity->causer_type);
        $this->assertSame('carrier capacity incident', $activity->properties['reason']);
        $this->assertSame('internal', $activity->properties['old']['provider_code']);
        $this->assertSame('alternate', $activity->properties['new']['provider_code']);
    }

    public function test_override_establishes_the_first_external_mode_and_audits_it(): void
    {
        $shipment = $this->createShipment();

        Sanctum::actingAs($this->admin);
        $this->postJson($this->overrideUrl($shipment), [
            'provider' => 'alternate',
            'reason' => 'first external carrier assignment',
        ])->assertOk();

        $updated = $shipment->fresh();
        $this->assertSame('sandbox', $updated->provider_mode);

        $activity = Activity::where('event', 'provider_override')->latest()->firstOrFail();
        $this->assertSame('internal', $activity->properties['old']['provider_mode']);
        $this->assertSame('sandbox', $activity->properties['new']['provider_mode']);
    }

    public function test_override_cannot_cross_between_sandbox_and_production_in_either_direction(): void
    {
        config([
            'shipping.providers.sandbox_carrier' => $this->providerConfig('sandbox'),
            'shipping.providers.production_carrier' => $this->providerConfig('production'),
        ]);

        foreach (['sandbox_carrier' => 'production_carrier', 'production_carrier' => 'sandbox_carrier'] as $from => $to) {
            config(['shipping.default_provider' => $from]);
            $shipment = $this->createShipment();

            try {
                app(ProviderAssignmentService::class)->override($shipment, $to, 'cross-environment attempt');
                $this->fail("Override from [{$from}] to [{$to}] must fail closed.");
            } catch (CarrierConfigurationException $exception) {
                $this->assertStringContainsString('mode mismatch', $exception->getMessage());
            }

            $fresh = $shipment->fresh();
            $this->assertSame($from, $fresh->provider_code);
            $this->assertSame($from === 'sandbox_carrier' ? 'sandbox' : 'production', $fresh->provider_mode);
        }

        $this->assertSame(0, Activity::where('event', 'provider_override')->count());
    }

    public function test_whitespace_only_reason_is_rejected_by_the_assignment_authority(): void
    {
        $shipment = $this->createShipment();

        try {
            app(ProviderAssignmentService::class)->override($shipment, 'alternate', " \t\n ");
            $this->fail('A whitespace-only override reason must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(__('shipping.audit.override_reason_required'), $exception->getMessage());
        }

        $this->assertSame('internal', $shipment->fresh()->provider_code);
        $this->assertSame(0, Activity::where('event', 'provider_override')->count());
    }

    public function test_override_is_refused_after_awb_creation(): void
    {
        $shipment = $this->createShipment();
        $shipment->forceFill(['awb_created_at' => now()])->save();

        Sanctum::actingAs($this->admin);

        $this->postJson($this->overrideUrl($shipment), [
            'provider' => 'alternate',
            'reason' => 'exception after label',
        ])->assertStatus(409);

        $this->assertSame('internal', $shipment->fresh()->provider_code);
        $this->assertSame(0, Activity::where('event', 'provider_override')->count());
    }

    public function test_override_is_refused_when_tracking_number_establishes_awb(): void
    {
        $shipment = $this->createShipment();
        $shipment->forceFill(['tracking_number' => 'AWB-EVIDENCE-148'])->save();

        Sanctum::actingAs($this->admin);

        $this->postJson($this->overrideUrl($shipment), [
            'provider' => 'alternate',
            'reason' => 'tracking number already issued',
        ])->assertStatus(409);

        $this->assertSame('internal', $shipment->fresh()->provider_code);
        $this->assertSame(0, Activity::where('event', 'provider_override')->count());
    }

    public function test_label_creation_establishes_awb_evidence_and_blocks_override(): void
    {
        $order = $this->createOrder();
        $warehouse = Warehouse::create([
            'code' => 'WH-148',
            'name' => 'Override warehouse',
            'status' => 'active',
            'is_default' => false,
        ]);
        $fulfillment = Fulfillment::create([
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'fulfillment_number' => 'F-148-'.uniqid(),
            'status' => 'ready_to_ship',
        ]);

        $shipment = app(ShipmentService::class)->createForFulfillment($fulfillment);

        $this->assertSame('label_created', $shipment->status);
        $this->assertNotNull($shipment->awb_created_at);

        Sanctum::actingAs($this->admin);
        $this->postJson($this->overrideUrl($shipment), [
            'provider' => 'alternate',
            'reason' => 'label already established',
        ])->assertStatus(409);

        $this->assertSame('internal', $shipment->fresh()->provider_code);
    }

    public function test_warehouse_scoped_actor_can_override_shipment_in_home_warehouse(): void
    {
        $shipment = $this->createFulfillmentShipment($this->homeWarehouse);

        Sanctum::actingAs($this->warehouseScoped);
        $response = $this->postJson($this->overrideUrl($shipment), [
            'provider' => 'alternate',
            'reason' => 'home warehouse capacity incident',
        ]);

        $response->assertOk();
        $this->assertSame('alternate', $shipment->fresh()->provider_code);

        $activity = Activity::where('event', 'provider_override')->latest()->first();
        $this->assertNotNull($activity);
        $this->assertSame($this->warehouseScoped->id, (int) $activity->causer_id);
    }

    public function test_warehouse_scoped_actor_cannot_override_shipment_in_another_warehouse(): void
    {
        $shipment = $this->createFulfillmentShipment($this->otherWarehouse);

        Sanctum::actingAs($this->warehouseScoped);
        $this->postJson($this->overrideUrl($shipment), [
            'provider' => 'alternate',
            'reason' => 'cross-warehouse attempt',
        ])->assertForbidden();

        $this->assertSame(0, Activity::where('event', 'provider_override')->count());
        $this->assertSame('internal', $shipment->fresh()->provider_code);
        $this->assertFalse((bool) $shipment->fresh()->provider_override);
    }

    public function test_warehouse_scoped_actor_cannot_override_order_only_shipment(): void
    {
        $shipment = $this->createShipment();

        Sanctum::actingAs($this->warehouseScoped);
        $this->postJson($this->overrideUrl($shipment), [
            'provider' => 'alternate',
            'reason' => 'order-only label has no warehouse scope',
        ])->assertForbidden();

        $this->assertSame(0, Activity::where('event', 'provider_override')->count());
        $this->assertSame('internal', $shipment->fresh()->provider_code);
    }

    public function test_override_requires_authentication(): void
    {
        $shipment = $this->createShipment();

        $this->postJson($this->overrideUrl($shipment), [
            'provider' => 'alternate',
            'reason' => 'anonymous attempt',
        ])->assertUnauthorized();

        $this->assertSame('internal', $shipment->fresh()->provider_code);
        $this->assertSame(0, Activity::where('event', 'provider_override')->count());
    }

    private function overrideUrl(Shipment $shipment): string
    {
        return '/api/v1/admin/shipments/' . $shipment->id . '/provider-override';
    }

    /** @param array<string,mixed> $extra */
    private function createShipment(array $extra = []): Shipment
    {
        return app(ShipmentService::class)->create(array_merge(['order_id' => $this->createOrder()->id], $extra));
    }

    private function createFulfillmentShipment(Warehouse $warehouse): Shipment
    {
        $fulfillment = Fulfillment::create([
            'order_id' => $this->createOrder()->id,
            'warehouse_id' => $warehouse->id,
            'fulfillment_number' => 'F-OVR-'.uniqid(),
            'status' => 'ready_to_ship',
        ]);

        return app(ShipmentService::class)->create([
            'order_id' => $fulfillment->order_id,
            'fulfillment_id' => $fulfillment->id,
        ]);
    }

    /** @return array<string,mixed> */
    private function providerConfig(string $mode): array
    {
        return [
            'adapter' => \App\Services\Shipment\Providers\NullCarrierGateway::class,
            'mode' => $mode,
            'account_code' => 'test-account',
            'service_level' => 'express',
            'channels' => ['web'],
            'geographies' => ['EG'],
            'polling' => [
                'supported' => false,
                'cursor_type' => null,
                'pagination' => null,
                'overlap_window_seconds' => 0,
            ],
            'production_approvals' => [[
                'approver' => 'release-manager',
                'approved_at' => '2026-11-25T00:00:00Z',
            ]],
            'modes' => [
                'sandbox' => [
                    'enabled' => $mode === 'sandbox',
                    'credentials' => $mode === 'sandbox' ? ['api_key' => 'sandbox-key'] : [],
                    'status_map' => ['version' => 1, 'statuses' => []],
                ],
                'production' => [
                    'enabled' => $mode === 'production',
                    'credentials' => $mode === 'production' ? ['api_key' => 'production-key'] : [],
                    'capability_verification' => [
                        'quote' => [
                            'verified_at' => '2026-11-25T00:00:00Z',
                            'evidence' => 'recorded production fixture',
                        ],
                    ],
                    'status_map' => ['version' => 1, 'statuses' => []],
                ],
            ],
        ];
    }

    private function createOrder(): Order
    {
        $user = User::factory()->create(['type' => 'customer']);

        return Order::create([
            'user_id' => $user->id,
            'name' => 'Provider order',
            'user_email' => $user->email,
            'user_phone' => '01000000000',
            'status' => 'completed',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 100,
            'total_price' => 100,
            'currency_code' => 'EGP',
            'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery',
            'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
    }
}

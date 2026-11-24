<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\Shipping\ProviderMode;
use App\Exceptions\CarrierConfigurationException;
use App\Exceptions\ProviderOverrideConflictException;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Services\Shipment\ProviderAssignmentService;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * #172: provider identity and the exact mapping version used at assignment
 * are snapshotted on the shipment. Historical rows keep NULL; no inference.
 */
class ProviderMappingVersionSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'broadcasting.connections.pusher.driver' => 'log',
            'shipping.default_provider' => 'internal',
            'shipping.providers.internal.modes.internal.status_map.version' => 'v172-internal',
        ]);
    }

    public function test_both_creation_paths_snapshot_provider_code_mode_and_configured_mapping_version(): void
    {
        $clientForged = app(ShipmentService::class)->create([
            'order_id' => $this->createOrder()->id,
            'provider_code' => 'forged',
            'provider_mode' => 'production',
            'mapping_version' => 'forged',
        ]);

        $this->assertSame('internal', $clientForged->provider_code);
        $this->assertSame(ProviderMode::INTERNAL->value, $clientForged->provider_mode);
        $this->assertSame('v172-internal', $clientForged->mapping_version);

        $fulfillment = $this->createFulfillment();
        $label = app(ShipmentService::class)->createForFulfillment($fulfillment);

        $this->assertSame('internal', $label->provider_code);
        $this->assertSame(ProviderMode::INTERNAL->value, $label->provider_mode);
        $this->assertSame('v172-internal', $label->mapping_version);
    }

    public function test_missing_or_invalid_mapping_version_configuration_fails_closed_without_a_shipment(): void
    {
        foreach ([null, '', ['nested' => 'array']] as $invalid) {
            config(['shipping.providers.internal.modes.internal.status_map.version' => $invalid]);

            try {
                app(ShipmentService::class)->create(['order_id' => $this->createOrder()->id]);
                $this->fail('A shipment must not be created without a valid mapping version.');
            } catch (CarrierConfigurationException $exception) {
                $this->assertStringContainsString('mapping version', $exception->getMessage());
            }
        }

        $this->assertSame(0, Shipment::count());
    }

    public function test_historical_rows_keep_null_mapping_version_and_are_not_backfilled(): void
    {
        $legacyId = DB::table('shipments')->insertGetId([
            'uuid' => 'legacy-172-'.uniqid(),
            'order_id' => $this->createOrder()->id,
            'status' => 'pending',
            'provider_code' => null,
            'provider_mode' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(DB::table('shipments')->where('id', $legacyId)->value('mapping_version'));

        $new = app(ShipmentService::class)->create(['order_id' => $this->createOrder()->id]);

        $this->assertSame('v172-internal', $new->mapping_version);
        $this->assertNull(DB::table('shipments')->where('id', $legacyId)->value('mapping_version'));
    }

    public function test_override_updates_mapping_version_consistently_and_audits_both_values(): void
    {
        config(['shipping.providers.alternate' => $this->providerConfig('sandbox', 'v172-alternate')]);
        $shipment = app(ShipmentService::class)->create(['order_id' => $this->createOrder()->id]);

        $overridden = app(ProviderAssignmentService::class)->override($shipment, 'alternate', 'carrier incident');

        $this->assertSame('alternate', $overridden->provider_code);
        $this->assertSame('sandbox', $overridden->provider_mode);
        $this->assertSame('v172-alternate', $overridden->mapping_version);

        $activity = Activity::where('event', 'provider_override')->latest()->firstOrFail();
        $this->assertSame('v172-internal', $activity->properties['old']['mapping_version']);
        $this->assertSame('v172-alternate', $activity->properties['new']['mapping_version']);
    }

    public function test_awb_lock_leaves_the_existing_mapping_version_untouched(): void
    {
        config(['shipping.providers.alternate' => $this->providerConfig('sandbox', 'v172-alternate')]);
        $shipment = app(ShipmentService::class)->create(['order_id' => $this->createOrder()->id]);
        $shipment->forceFill(['awb_created_at' => now()])->save();

        try {
            app(ProviderAssignmentService::class)->override($shipment, 'alternate', 'too late');
            $this->fail('An override after AWB creation must be refused.');
        } catch (ProviderOverrideConflictException $exception) {
            $this->assertStringContainsString('AWB', $exception->getMessage());
        }

        $this->assertSame('internal', $shipment->fresh()->provider_code);
        $this->assertSame('v172-internal', $shipment->fresh()->mapping_version);
        $this->assertSame(0, Activity::where('event', 'provider_override')->count());
    }

    /** @return array<string,mixed> */
    private function providerConfig(string $mode, string $mappingVersion): array
    {
        return [
            'adapter' => \App\Services\Shipment\Providers\NullCarrierGateway::class,
            'mode' => $mode,
            'service_level' => 'express',
            'channels' => ['web'],
            'geographies' => ['EG'],
            'polling' => [
                'supported' => false,
                'cursor_type' => null,
                'pagination' => null,
                'overlap_window_seconds' => 0,
            ],
            'modes' => [
                $mode => [
                    'enabled' => true,
                    'credentials' => ['api_key' => 'test-key'],
                    'status_map' => ['version' => $mappingVersion, 'statuses' => []],
                ],
            ],
        ];
    }

    private function createFulfillment(): Fulfillment
    {
        $warehouse = Warehouse::create([
            'code' => 'WH-172-'.uniqid(),
            'name' => 'Mapping snapshot warehouse',
            'status' => 'active',
            'is_default' => false,
        ]);

        return Fulfillment::create([
            'order_id' => $this->createOrder()->id,
            'warehouse_id' => $warehouse->id,
            'fulfillment_number' => 'F-172-'.uniqid(),
            'status' => 'ready_to_ship',
        ]);
    }

    private function createOrder(): Order
    {
        $user = User::factory()->create(['type' => 'customer']);

        return Order::create([
            'user_id' => $user->id,
            'name' => 'Mapping version order',
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

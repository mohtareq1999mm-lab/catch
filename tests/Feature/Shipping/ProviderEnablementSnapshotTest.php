<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\Shipping\CarrierCapability;
use App\Exceptions\CarrierConfigurationException;
use App\Models\Shipment;
use App\Services\Shipment\CarrierGatewayFactory;
use App\Services\Shipment\ProviderAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Tests\Support\Shipping\ConfiguredCarrierGateway;
use Tests\TestCase;

/**
 * #179: production enablement is deployment evidence, and disabling a
 * provider is a read-only status change for historical shipments.
 */
class ProviderEnablementSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_requires_verification_evidence_and_recorded_approvals(): void
    {
        config(['shipping.providers.gated' => $this->productionProvider()]);
        $factory = app(CarrierGatewayFactory::class);

        try {
            $factory->make('gated', capability: CarrierCapability::QUOTE);
            $this->fail('Production enablement without approvals must fail closed.');
        } catch (CarrierConfigurationException $exception) {
            $this->assertStringContainsString('no recorded production approvals', $exception->getMessage());
        }

        $status = $factory->status('gated');
        $this->assertSame(['provider', 'mode', 'enabled', 'verified_capabilities', 'mapping_version_present'], array_keys($status));
        $this->assertFalse($status['enabled'], 'Status must not report an unapproved production provider as enabled.');
        try {
            app(ProviderAssignmentService::class)->forProvider('gated');
            $this->fail('An unapproved production provider must not be assignable.');
        } catch (CarrierConfigurationException $exception) {
            $this->assertStringContainsString('no recorded production approvals', $exception->getMessage());
        }

        config(['shipping.providers.gated.modes.production.capability_verification' => []]);
        config(['shipping.providers.gated.production_approvals' => [[
            'approver' => 'release-manager',
            'approved_at' => '2026-11-25T00:00:00Z',
        ]]]);

        try {
            $factory->make('gated', capability: CarrierCapability::QUOTE);
            $this->fail('Production enablement without verification evidence must fail closed.');
        } catch (CarrierConfigurationException $exception) {
            $this->assertStringContainsString('no production verification evidence', $exception->getMessage());
        }

        $this->assertFalse($factory->status('gated')['enabled']);
        try {
            app(ProviderAssignmentService::class)->forProvider('gated');
            $this->fail('Unverified production evidence must not be assignable.');
        } catch (CarrierConfigurationException $exception) {
            $this->assertStringContainsString('no production verification evidence', $exception->getMessage());
        }

        config(['shipping.providers.gated.modes.production' => array_merge(
            $this->productionProvider()['modes']['production'],
            ['capability_verification' => [
                'quote' => [
                    'verified_at' => '2026-11-25T00:00:00Z',
                    'evidence' => 'recorded production fixture',
                ],
            ]],
        )]);

        $gateway = $factory->make('gated', capability: CarrierCapability::QUOTE);

        $this->assertInstanceOf(ConfiguredCarrierGateway::class, $gateway);
        $this->assertSame('production', $gateway->context()->mode);
        $this->assertTrue($factory->status('gated')['enabled']);
        $this->assertSame(
            ['quote'],
            $factory->status('gated')['verified_capabilities'],
        );
        $this->assertArrayNotHasKey('production_approvals', $factory->status('gated'));
    }

    public function test_disabling_a_provider_never_rewrites_existing_shipment_snapshots(): void
    {
        config(['shipping.providers.gated' => $this->productionProvider()]);
        $order = Order::create([
            'user_id' => \App\Models\User::factory()->create(['type' => 'customer'])->id,
            'name' => 'Snapshot order',
            'user_email' => 'snapshot-179@example.test',
            'user_phone' => '01000000000',
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'price' => 100,
            'total_price' => 100,
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);
        $shipment = Shipment::create([
            'order_id' => $order->id,
            'status' => 'pending',
            'provider_code' => 'gated',
            'provider_mode' => 'production',
            'provider_service_level' => 'express',
            'mapping_version' => 'snap-179',
        ]);

        config(['shipping.providers.gated.modes.production.enabled' => false]);
        $factory = app(CarrierGatewayFactory::class);

        $this->assertFalse($factory->status('gated')['enabled']);

        try {
            $factory->makeForShipment($shipment);
            $this->fail('A disabled provider must fail closed.');
        } catch (CarrierConfigurationException $exception) {
            $this->assertStringContainsString('is disabled', $exception->getMessage());
        }

        $fresh = $shipment->fresh();
        $this->assertSame('gated', $fresh->provider_code);
        $this->assertSame('production', $fresh->provider_mode);
        $this->assertSame('express', $fresh->provider_service_level);
        $this->assertSame('snap-179', $fresh->mapping_version);
    }

    /** @return array<string,mixed> */
    private function productionProvider(): array
    {
        return [
            'adapter' => ConfiguredCarrierGateway::class,
            'mode' => 'production',
            'account_code' => 'GATED-ACCOUNT',
            'service_level' => 'express',
            'channels' => ['web'],
            'geographies' => ['EG'],
            'polling' => [
                'supported' => false,
                'cursor_type' => null,
                'pagination' => null,
                'overlap_window_seconds' => 0,
            ],
            'production_approvals' => [],
            'modes' => [
                'sandbox' => ['enabled' => false, 'credentials' => []],
                'production' => [
                    'enabled' => true,
                    'base_url' => 'https://gated.example.test',
                    'credentials' => ['api_key' => 'production-key'],
                    'timeout_seconds' => 5,
                    'retry' => ['attempts' => 2, 'backoff_ms' => 100, 'max_backoff_ms' => 1000],
                    'queues' => ['dispatch' => 'shipping.dispatch', 'webhook' => 'shipping.webhook'],
                    'webhook' => ['version' => 'v1'],
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
}

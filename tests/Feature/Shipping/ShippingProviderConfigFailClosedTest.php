<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Exceptions\CarrierConfigurationException;
use App\Models\Shipment;
use App\Services\Shipment\CarrierGatewayFactory;
use App\Services\Shipment\ProviderAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingProviderConfigFailClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_assignment_fails_closed_when_mapping_version_missing(): void
    {
        config([
            'shipping.providers.null.mode' => 'sandbox',
            'shipping.providers.null.service_level' => 'standard',
            'shipping.providers.null.modes.sandbox.enabled' => true,
            'shipping.providers.null.modes.sandbox.credentials' => ['api_key' => 'k', 'api_secret' => 's'],
            'shipping.providers.null.modes.sandbox.capability_verification' => [
                'create_shipment' => ['verified_at' => '2026-01-01T00:00:00Z', 'evidence' => 'test'],
            ],
            'shipping.providers.null.modes.sandbox.status_map' => ['version' => '', 'statuses' => []],
        ]);

        $this->expectException(CarrierConfigurationException::class);
        app(ProviderAssignmentService::class)->forProvider('null');
    }

    public function test_provider_assignment_fails_closed_when_credentials_missing(): void
    {
        config([
            'shipping.providers.null.mode' => 'sandbox',
            'shipping.providers.null.service_level' => 'standard',
            'shipping.providers.null.modes.sandbox.enabled' => true,
            'shipping.providers.null.modes.sandbox.credentials' => ['api_key' => '', 'api_secret' => ''],
            'shipping.providers.null.modes.sandbox.status_map' => ['version' => 1, 'statuses' => []],
        ]);

        $this->expectException(CarrierConfigurationException::class);
        app(ProviderAssignmentService::class)->forProvider('null');
    }

    public function test_provider_assignment_fails_closed_when_mode_invalid(): void
    {
        config([
            'shipping.providers.null.mode' => 'bogus',
            'shipping.providers.null.service_level' => 'standard',
            'shipping.providers.null.modes.bogus.enabled' => true,
            'shipping.providers.null.modes.bogus.credentials' => ['api_key' => 'k'],
            'shipping.providers.null.modes.bogus.status_map' => ['version' => 1, 'statuses' => []],
        ]);

        $this->expectException(CarrierConfigurationException::class);
        app(ProviderAssignmentService::class)->forProvider('null');
    }

    public function test_factory_fails_closed_when_mapping_version_missing_for_resolved_mode(): void
    {
        config([
            'shipping.providers.carrier' => [
                'adapter' => \Tests\Support\Shipping\ConfiguredCarrierGateway::class,
                'mode' => 'sandbox',
                'account_code' => 'EG-ACCOUNT',
                'channels' => ['web'],
                'geographies' => ['EG'],
                'service_level' => 'standard',
                'modes' => [
                    'sandbox' => [
                        'enabled' => true,
                        'base_url' => 'https://sandbox.example.test',
                        'credentials' => ['api_key' => 'sandbox-key'],
                        'capability_verification' => [
                            'quote' => ['verified_at' => '2026-11-01T00:00:00Z', 'evidence' => 'recorded fixture'],
                        ],
                        'status_map' => ['version' => '', 'statuses' => []],
                    ],
                    'production' => ['enabled' => false, 'credentials' => ['api_key' => null], 'capability_verification' => [], 'status_map' => ['version' => 1, 'statuses' => []]],
                ],
            ],
        ]);

        $this->expectException(CarrierConfigurationException::class);
        app(CarrierGatewayFactory::class)->resolveConfiguredMappingVersion('carrier');
    }

    public function test_factory_fails_closed_when_credentials_missing_for_resolved_mode(): void
    {
        config([
            'shipping.providers.carrier' => [
                'adapter' => \Tests\Support\Shipping\ConfiguredCarrierGateway::class,
                'mode' => 'sandbox',
                'account_code' => 'EG-ACCOUNT',
                'channels' => ['web'],
                'geographies' => ['EG'],
                'service_level' => 'standard',
                'modes' => [
                    'sandbox' => [
                        'enabled' => true,
                        'base_url' => 'https://sandbox.example.test',
                        'credentials' => ['api_key' => ''],
                        'capability_verification' => [
                            'quote' => ['verified_at' => '2026-11-01T00:00:00Z', 'evidence' => 'recorded fixture'],
                        ],
                        'status_map' => ['version' => 1, 'statuses' => []],
                    ],
                    'production' => ['enabled' => false, 'credentials' => ['api_key' => null], 'capability_verification' => [], 'status_map' => ['version' => 1, 'statuses' => []]],
                ],
            ],
        ]);

        $this->expectException(CarrierConfigurationException::class);
        app(CarrierGatewayFactory::class)->assertProviderConfigResolvable('carrier', 'sandbox');
    }
}


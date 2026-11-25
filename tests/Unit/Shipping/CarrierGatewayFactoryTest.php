<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\DTOs\Shipping\CarrierGatewayContext;
use App\Enums\Shipping\CarrierCapability;
use App\Exceptions\CarrierConfigurationException;
use App\Models\Shipment;
use App\Services\Shipment\CarrierGatewayFactory;
use Tests\Support\Shipping\ConfiguredCarrierGateway;
use Tests\Support\Shipping\NeverConstructedCarrier;
use Tests\TestCase;

class CarrierGatewayFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        NeverConstructedCarrier::$constructions = 0;
        ConfiguredCarrierGateway::$constructions = 0;
    }

    public function test_unknown_provider_fails_closed(): void
    {
        config(['shipping.providers' => []]);

        $this->expectException(CarrierConfigurationException::class);
        $this->expectExceptionMessage('Unknown carrier provider [ghost]');

        app(CarrierGatewayFactory::class)->make('ghost');
    }

    public function test_disabled_provider_fails_closed(): void
    {
        config(['shipping.providers' => [
            'carrier' => [
                'mode' => 'sandbox',
                'polling' => [
                    'supported' => false,
                    'cursor_type' => null,
                    'pagination' => null,
                    'overlap_window_seconds' => 0,
                ],
                'modes' => [
                    'sandbox' => ['enabled' => false, 'credentials' => []],
                    'production' => ['enabled' => false, 'credentials' => []],
                ],
            ],
        ]]);

        $this->expectException(CarrierConfigurationException::class);
        $this->expectExceptionMessage('Carrier provider [carrier] is disabled');

        app(CarrierGatewayFactory::class)->make('carrier');
    }

    public function test_missing_credentials_fail_closed_without_adapter_construction(): void
    {
        config(['shipping.providers' => [
            'carrier' => $this->validProvider([
                'adapter' => NeverConstructedCarrier::class,
                'modes' => ['sandbox' => ['credentials' => ['api_key' => '', 'api_secret' => null]]],
            ]),
        ]]);

        try {
            app(CarrierGatewayFactory::class)->make('carrier');
            $this->fail('Missing credentials must fail closed.');
        } catch (CarrierConfigurationException $exception) {
            $this->assertStringContainsString('missing credentials', $exception->getMessage());
        }

        $this->assertSame(0, NeverConstructedCarrier::$constructions);
    }

    public function test_mixed_sandbox_and_production_configuration_fails_closed(): void
    {
        config(['shipping.providers' => [
            'carrier' => $this->validProvider([
                'adapter' => NeverConstructedCarrier::class,
                'modes' => ['production' => ['enabled' => true, 'credentials' => ['api_key' => 'production-key']]],
            ]),
        ]]);

        try {
            app(CarrierGatewayFactory::class)->make('carrier');
            $this->fail('Mixed mode configuration must fail closed.');
        } catch (CarrierConfigurationException $exception) {
            $this->assertStringContainsString('mixed sandbox/production', $exception->getMessage());
        }

        $this->assertSame(0, NeverConstructedCarrier::$constructions);
    }

    public function test_configuration_flag_without_credentials_is_not_proof_of_mode_isolation(): void
    {
        foreach (['sandbox', 'production'] as $activeMode) {
            config(['shipping.providers.carrier' => $this->providerForMode($activeMode)]);
            $otherMode = $activeMode === 'sandbox' ? 'production' : 'sandbox';
            config(["shipping.providers.carrier.modes.{$otherMode}.enabled" => true]);

            try {
                app(CarrierGatewayFactory::class)->make('carrier');
                $this->fail("A bare [{$otherMode}] flag must not be accepted.");
            } catch (CarrierConfigurationException $exception) {
                $this->assertStringContainsString('mixed sandbox/production', $exception->getMessage());
            }
        }

        $this->assertSame(0, ConfiguredCarrierGateway::$constructions);
    }

    public function test_gateway_resolution_uses_the_shipment_mode_snapshot_in_both_directions(): void
    {
        foreach (['sandbox', 'production'] as $snapshot) {
            $flipped = $snapshot === 'sandbox' ? 'production' : 'sandbox';
            config(['shipping.providers.carrier' => $this->providerForMode($flipped)]);
            $shipment = new Shipment(['provider_code' => 'carrier', 'provider_mode' => $snapshot]);

            try {
                app(CarrierGatewayFactory::class)->makeForShipment($shipment);
                $this->fail("A [{$snapshot}] shipment must not follow a [{$flipped}] config flip.");
            } catch (CarrierConfigurationException $exception) {
                $this->assertStringContainsString('mode mismatch', $exception->getMessage());
            }
        }

        $this->assertSame(0, ConfiguredCarrierGateway::$constructions);
    }

    public function test_missing_capability_verification_fails_closed(): void
    {
        config(['shipping.providers' => [
            'carrier' => $this->validProvider([
                'adapter' => NeverConstructedCarrier::class,
                'modes' => ['sandbox' => ['capability_verification' => ['quote' => null]]],
            ]),
        ]]);

        try {
            app(CarrierGatewayFactory::class)->make('carrier', capability: CarrierCapability::QUOTE);
            $this->fail('Missing capability verification must fail closed.');
        } catch (CarrierConfigurationException $exception) {
            $this->assertStringContainsString('no verification for capability [quote]', $exception->getMessage());
        }

        $this->assertSame(0, NeverConstructedCarrier::$constructions);
    }

    public function test_unsupported_channel_and_geography_fail_closed(): void
    {
        config(['shipping.providers' => ['carrier' => $this->validProvider()]]);

        $factory = app(CarrierGatewayFactory::class);

        try {
            $factory->make('carrier', channel: 'pos');
            $this->fail('Unsupported channel must fail closed.');
        } catch (CarrierConfigurationException $exception) {
            $this->assertStringContainsString('channel [pos] is not configured', $exception->getMessage());
        }

        try {
            $factory->make('carrier', geography: 'SA');
            $this->fail('Unsupported geography must fail closed.');
        } catch (CarrierConfigurationException $exception) {
            $this->assertStringContainsString('geography [SA] is not configured', $exception->getMessage());
        }

        $this->assertSame(0, ConfiguredCarrierGateway::$constructions);
    }

    public function test_adapter_without_requested_capability_interface_fails_closed(): void
    {
        config(['shipping.providers' => ['carrier' => $this->validProvider([
            'modes' => ['sandbox' => [
                'capability_verification' => [
                    'tracking' => ['verified_at' => '2026-11-01T00:00:00Z', 'evidence' => 'recorded fixture'],
                ],
            ]],
        ])]]);

        try {
            app(CarrierGatewayFactory::class)->make('carrier', capability: CarrierCapability::TRACKING);
            $this->fail('An unverified-at-runtime capability must fail closed.');
        } catch (CarrierConfigurationException $exception) {
            $this->assertStringContainsString('does not implement capability [tracking]', $exception->getMessage());
        }

        $this->assertSame(0, ConfiguredCarrierGateway::$constructions);
    }

    public function test_valid_configuration_resolves_adapter_with_non_secret_context(): void
    {
        config(['shipping.providers' => ['carrier' => $this->validProvider()]]);

        $gateway = app(CarrierGatewayFactory::class)->make(
            'carrier',
            channel: 'web',
            geography: 'EG',
            capability: CarrierCapability::QUOTE,
        );

        $this->assertInstanceOf(ConfiguredCarrierGateway::class, $gateway);
        $context = $gateway->context();
        $this->assertInstanceOf(CarrierGatewayContext::class, $context);
        $this->assertSame('carrier', $context->providerCode);
        $this->assertSame('sandbox', $context->mode);
        $this->assertSame('EG-ACCOUNT', $context->accountCode);
        $this->assertSame('web', $context->channel);
        $this->assertSame('EG', $context->geography);
        $this->assertSame(['quote'], $context->verifiedCapabilities);
        $this->assertSame(1, ConfiguredCarrierGateway::$constructions);
    }

    /** @return array<string,mixed> */
    private function providerForMode(string $mode): array
    {
        $provider = $this->validProvider(['mode' => $mode]);

        if ($mode === 'production') {
            $production = $provider['modes']['sandbox'];
            $production['base_url'] = 'https://api.example.test';
            $production['credentials'] = ['api_key' => 'production-key'];
            $provider['modes']['production'] = $production;
            $provider['modes']['sandbox'] = ['enabled' => false, 'credentials' => []];
        }

        return $provider;
    }

    /** @param array<string,mixed> $overrides */
    private function validProvider(array $overrides = []): array
    {
        $provider = [
            'adapter' => ConfiguredCarrierGateway::class,
            'mode' => 'sandbox',
            'account_code' => 'EG-ACCOUNT',
            'channels' => ['web'],
            'geographies' => ['EG'],
            'polling' => [
                'supported' => false,
                'cursor_type' => null,
                'pagination' => null,
                'overlap_window_seconds' => 0,
            ],
            'modes' => [
                'sandbox' => [
                    'enabled' => true,
                    'base_url' => 'https://sandbox.example.test',
                    'credentials' => ['api_key' => 'sandbox-key'],
                    'timeout_seconds' => 5,
                    'retry' => ['attempts' => 2, 'backoff_ms' => 100, 'max_backoff_ms' => 1000],
                    'queues' => ['dispatch' => 'shipping.dispatch', 'webhook' => 'shipping.webhook'],
                    'webhook' => ['secret' => 'hook-secret', 'version' => 'v1'],
                    'capability_verification' => [
                        'quote' => ['verified_at' => '2026-11-01T00:00:00Z', 'evidence' => 'recorded fixture'],
                    ],
                    'status_map' => ['version' => 1, 'statuses' => []],
                ],
                'production' => [
                    'enabled' => false,
                    'base_url' => 'https://api.example.test',
                    'credentials' => ['api_key' => null],
                    'capability_verification' => [],
                    'status_map' => ['version' => 1, 'statuses' => []],
                ],
            ],
        ];

        return array_replace_recursive($provider, $overrides);
    }
}

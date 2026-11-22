<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\Exceptions\CarrierConfigurationException;
use App\Services\Shipment\CarrierGatewayFactory;
use App\Services\Shipment\Providers\NullCarrierGateway;
use Tests\TestCase;

class ShippingConfigTest extends TestCase
{
    public function test_shipped_configuration_is_disabled_and_has_no_verified_capabilities(): void
    {
        $factory = app(CarrierGatewayFactory::class);

        $this->assertTrue($factory->hasProvider('null'));
        $this->assertSame([
            'provider' => 'null',
            'mode' => config('shipping.providers.null.mode'),
            'enabled' => false,
            'verified_capabilities' => [],
        ], $factory->status('null'));

        $this->expectException(CarrierConfigurationException::class);
        $this->expectExceptionMessage('is disabled');
        $factory->make('null');
    }

    public function test_credentials_and_webhook_secret_are_env_backed_only(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../config/shipping.php');

        foreach ([
            'SHIPPING_NULL_SANDBOX_API_KEY',
            'SHIPPING_NULL_SANDBOX_API_SECRET',
            'SHIPPING_NULL_SANDBOX_WEBHOOK_SECRET',
            'SHIPPING_NULL_PRODUCTION_API_KEY',
            'SHIPPING_NULL_PRODUCTION_API_SECRET',
            'SHIPPING_NULL_PRODUCTION_WEBHOOK_SECRET',
        ] as $variable) {
            $this->assertStringContainsString("env('{$variable}')", $source);
        }
        $this->assertStringContainsString(NullCarrierGateway::class, $source);
    }

    public function test_separate_sandbox_and_production_blocks_are_present(): void
    {
        $modes = config('shipping.providers.null.modes');

        $this->assertIsArray($modes);
        $this->assertArrayHasKey('sandbox', $modes);
        $this->assertArrayHasKey('production', $modes);
        foreach ($modes as $block) {
            $this->assertIsArray($block);
            $this->assertArrayHasKey('base_url', $block);
            $this->assertArrayHasKey('timeout_seconds', $block);
            $this->assertArrayHasKey('retry', $block);
            $this->assertArrayHasKey('queues', $block);
            $this->assertArrayHasKey('webhook', $block);
            $this->assertArrayHasKey('capability_verification', $block);
            $this->assertArrayHasKey('status_map', $block);
        }
    }
}

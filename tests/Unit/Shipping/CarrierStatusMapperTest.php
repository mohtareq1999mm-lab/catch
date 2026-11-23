<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\Enums\ShipmentStatus;
use App\Enums\Shipping\ProviderEventExceptionType;
use App\Services\Shipment\CarrierStatusMapper;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

/**
 * #151: provider status maps are deployment-owned, versioned, and fail closed.
 *
 * These cases use a test-only registered provider: the shipped configuration
 * deliberately contains no production status translations.
 */
final class CarrierStatusMapperTest extends TestCase
{
    private Repository $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new Repository();
        $this->registerProvider([
            'version' => 7,
            'status_map_owner' => 'shipping-platform',
            'statuses' => [
                'label_created' => ShipmentStatus::LABEL_CREATED->value,
                'in_transit' => ShipmentStatus::IN_TRANSIT->value,
            ],
        ]);
    }

    public function test_known_status_uses_the_registered_versioned_map(): void
    {
        $mapping = $this->mapper()->map('test_carrier', 'sandbox', 'in_transit');

        $this->assertSame(ShipmentStatus::IN_TRANSIT->value, $mapping->mappedStatus);
        $this->assertSame('7', $mapping->mappingVersion);
        $this->assertNull($mapping->exceptionType);
        $this->assertTrue($mapping->canTransition());
    }

    public function test_missing_raw_status_fails_closed(): void
    {
        foreach ([null, '', '   ', 'unknown'] as $rawStatus) {
            $mapping = $this->mapper()->map('test_carrier', 'sandbox', $rawStatus);

            $this->assertNull($mapping->mappedStatus);
            $this->assertSame(ProviderEventExceptionType::UNKNOWN_STATUS, $mapping->exceptionType);
            $this->assertSame('7', $mapping->mappingVersion);
            $this->assertFalse($mapping->canTransition());
        }
    }

    public function test_unlisted_status_fails_closed(): void
    {
        $mapping = $this->mapper()->map('test_carrier', 'sandbox', 'brand_new_provider_status');

        $this->assertNull($mapping->mappedStatus);
        $this->assertSame(ProviderEventExceptionType::UNKNOWN_STATUS, $mapping->exceptionType);
    }

    public function test_ambiguous_status_fails_closed(): void
    {
        $this->config->set('shipping.providers.test_carrier.modes.sandbox.status_map.statuses', [
            'in transit' => ShipmentStatus::IN_TRANSIT->value,
            'IN_TRANSIT' => ShipmentStatus::DELIVERED->value,
        ]);

        $mapping = $this->mapper()->map('test_carrier', 'sandbox', 'in_transit');

        $this->assertNull($mapping->mappedStatus);
        $this->assertSame(ProviderEventExceptionType::AMBIGUOUS_STATUS, $mapping->exceptionType);
        $this->assertSame('7', $mapping->mappingVersion);
    }

    public function test_lost_and_damaged_are_never_force_mapped_even_when_configured(): void
    {
        $this->config->set('shipping.providers.test_carrier.modes.sandbox.status_map.statuses', [
            'lost' => ShipmentStatus::RETURNED->value,
            'damaged' => ShipmentStatus::FAILED_DELIVERY->value,
        ]);

        foreach (['lost', 'carrier_reports_damaged'] as $rawStatus) {
            $mapping = $this->mapper()->map('test_carrier', 'sandbox', $rawStatus);

            $this->assertNull($mapping->mappedStatus);
            $this->assertSame(ProviderEventExceptionType::LOST_OR_DAMAGED, $mapping->exceptionType);
            $this->assertFalse($mapping->canTransition());
        }
    }

    public function test_unregistered_provider_fails_closed(): void
    {
        $mapping = $this->mapper()->map('bosta', 'sandbox', 'in_transit');

        $this->assertNull($mapping->mappedStatus);
        $this->assertSame(ProviderEventExceptionType::UNREGISTERED_PROVIDER, $mapping->exceptionType);
        $this->assertSame('', $mapping->mappingVersion);
    }

    public function test_map_without_version_fails_closed(): void
    {
        $this->registerProvider(['statuses' => ['in_transit' => ShipmentStatus::IN_TRANSIT->value]]);

        $mapping = $this->mapper()->map('test_carrier', 'sandbox', 'in_transit');

        $this->assertNull($mapping->mappedStatus);
        $this->assertSame(ProviderEventExceptionType::INVALID_MAPPING_CONFIG, $mapping->exceptionType);
        $this->assertSame('', $mapping->mappingVersion);
    }

    public function test_non_canonical_target_fails_closed(): void
    {
        $this->registerProvider([
            'version' => 8,
            'statuses' => ['in_transit' => 'delivered_by_carrier'],
        ]);

        $mapping = $this->mapper()->map('test_carrier', 'sandbox', 'in_transit');

        $this->assertNull($mapping->mappedStatus);
        $this->assertSame(ProviderEventExceptionType::INVALID_MAPPING_CONFIG, $mapping->exceptionType);
        $this->assertSame('8', $mapping->mappingVersion);
    }

    /** @param array<string,mixed> $statusMap */
    private function registerProvider(array $statusMap): void
    {
        $this->config->set('shipping.providers.test_carrier', [
            'mode' => 'sandbox',
            'modes' => ['sandbox' => ['status_map' => $statusMap]],
        ]);
    }

    private function mapper(): CarrierStatusMapper
    {
        return new CarrierStatusMapper($this->config);
    }
}

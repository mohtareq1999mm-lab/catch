<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\Enums\ShipmentStatus;
use App\Services\Shipment\CarrierStatusMapper;
use App\Support\ProviderCode;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

/**
 * #148 / #151: one fail-closed provider-code rule for assignment and mapping.
 */
final class ProviderCodeTest extends TestCase
{
    /** @dataProvider validCodes */
    public function test_canonical_codes_are_accepted(string $code): void
    {
        $this->assertTrue(ProviderCode::isValid($code));
    }

    /** @dataProvider invalidCodes */
    public function test_malformed_codes_are_rejected(string $code): void
    {
        $this->assertFalse(ProviderCode::isValid($code));
    }

    public function test_mapper_rejects_a_code_the_assignment_rule_rejects(): void
    {
        $code = 'Not_A_Code';
        $mapper = new CarrierStatusMapper(new Repository());

        $this->assertFalse(ProviderCode::isValid($code));
        $this->assertNull($mapper->map($code, 'sandbox', 'in_transit')->mappedStatus);
    }

    public function test_registered_code_maps_to_a_canonical_status_case(): void
    {
        $config = new Repository();
        $config->set('shipping.providers.internal.modes.internal.status_map', [
            'version' => 3,
            'statuses' => ['in_transit' => ShipmentStatus::IN_TRANSIT->value],
        ]);

        $mapping = (new CarrierStatusMapper($config))->map('internal', 'internal', 'in_transit');

        $this->assertSame(ShipmentStatus::IN_TRANSIT, $mapping->mappedStatus);
    }

    public static function validCodes(): array
    {
        return [
            'letters' => ['internal'],
            'digits and separators' => ['carrier-2_test'],
            'maximum length' => [str_repeat('a', 32)],
        ];
    }

    public static function invalidCodes(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Carrier'],
            'space' => ['bad code'],
            'too long' => [str_repeat('a', 33)],
            'trailing newline' => ["carrier\n"],
        ];
    }
}

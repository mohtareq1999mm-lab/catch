<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\Enums\Shipping\CarrierCapability;
use App\Services\Shipment\Contracts\CancelsCarrierShipments;
use App\Services\Shipment\Contracts\CreatesCarrierShipments;
use App\Services\Shipment\Contracts\LinksCarrierReturns;
use App\Services\Shipment\Contracts\ProcessesCarrierBulk;
use App\Services\Shipment\Contracts\ProvidesCarrierLabels;
use App\Services\Shipment\Contracts\ProvidesCarrierQuotes;
use App\Services\Shipment\Contracts\ProvidesCarrierTracking;
use App\Services\Shipment\Contracts\ProvidesCodEvidence;
use App\Services\Shipment\Contracts\SchedulesCarrierPickup;
use App\Services\Shipment\Contracts\VerifiesCarrierWebhooks;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** #120 line 46: every declared capability maps to exactly one narrow contract. */
class CarrierCapabilityContractTest extends TestCase
{
    public function test_every_capability_case_maps_to_its_narrow_contract(): void
    {
        $expected = [
            'QUOTE' => ProvidesCarrierQuotes::class,
            'CREATE_SHIPMENT' => CreatesCarrierShipments::class,
            'CANCEL_SHIPMENT' => CancelsCarrierShipments::class,
            'LABEL' => ProvidesCarrierLabels::class,
            'PICKUP' => SchedulesCarrierPickup::class,
            'TRACKING' => ProvidesCarrierTracking::class,
            'WEBHOOK_VERIFICATION' => VerifiesCarrierWebhooks::class,
            'RETURNS' => LinksCarrierReturns::class,
            'BULK' => ProcessesCarrierBulk::class,
            'COD_EVIDENCE' => ProvidesCodEvidence::class,
        ];

        $cases = CarrierCapability::cases();
        $this->assertSame(array_keys($expected), array_map(static fn ($case) => $case->name, $cases));

        foreach ($expected as $name => $contract) {
            $this->assertTrue(interface_exists($contract), $contract.' must exist.');

            /** @var CarrierCapability $case */
            $case = constant(CarrierCapability::class.'::'.$name);
            $this->assertSame($contract, $case->contract());
            $this->assertCount(1, (new ReflectionClass($contract))->getMethods(ReflectionMethod::IS_PUBLIC), $contract.' must stay narrow.');
        }

        $this->assertCount(count($expected), array_unique(array_values($expected)));
    }
}

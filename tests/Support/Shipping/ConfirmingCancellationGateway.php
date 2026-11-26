<?php

declare(strict_types=1);

namespace Tests\Support\Shipping;

use App\DTOs\Shipping\CarrierGatewayContext;
use App\DTOs\Shipping\CarrierShipment;
use App\Enums\Shipping\CarrierCapability;
use App\Services\Shipment\Contracts\CarrierGatewayContract;
use App\Services\Shipment\Contracts\CancelsCarrierShipments;
use RuntimeException;

/**
 * #187: deterministic cancellation double. `fail` simulates the provider
 * refusing the cancellation; otherwise the gateway confirms cancellation.
 */
class ConfirmingCancellationGateway implements CarrierGatewayContract, CancelsCarrierShipments
{
    public static bool $fail = false;
    public static int $calls = 0;
    public static string $lastExternalId = '';

    public function __construct(private readonly CarrierGatewayContext $context) {}

    public function context(): CarrierGatewayContext
    {
        return $this->context;
    }

    /** @return list<CarrierCapability> */
    public function capabilities(): array
    {
        return [CarrierCapability::CANCEL_SHIPMENT];
    }

    public function cancelShipment(string $externalShipmentId): CarrierShipment
    {
        self::$calls++;
        self::$lastExternalId = $externalShipmentId;

        if (self::$fail) {
            throw new RuntimeException('provider rejected cancellation');
        }

        return new CarrierShipment(
            $this->context->providerCode,
            $this->context->mode,
            ['shipment_id' => $externalShipmentId],
            null,
            'redacted://shipping/cancel/local-test',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Shipment\Providers;

use App\DTOs\Shipping\CarrierGatewayContext;
use App\Enums\Shipping\CarrierCapability;
use App\Services\Shipment\Contracts\CarrierGatewayContract;

/**
 * Disabled placeholder adapter. It performs no outbound work and declares no
 * capabilities; real adapters arrive only after capability verification.
 */
class NullCarrierGateway implements CarrierGatewayContract
{
    public function __construct(private readonly CarrierGatewayContext $context) {}

    public function context(): CarrierGatewayContext
    {
        return $this->context;
    }

    /** @return list<CarrierCapability> */
    public function capabilities(): array
    {
        return [];
    }
}

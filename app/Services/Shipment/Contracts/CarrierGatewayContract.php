<?php

declare(strict_types=1);

namespace App\Services\Shipment\Contracts;

use App\DTOs\Shipping\CarrierGatewayContext;
use App\Enums\Shipping\CarrierCapability;

/**
 * Mandatory identity seam only. Operations live in narrow capability
 * interfaces so unsupported actions cannot be called by accident.
 */
interface CarrierGatewayContract
{
    public function context(): CarrierGatewayContext;

    /** @return list<CarrierCapability> */
    public function capabilities(): array;
}

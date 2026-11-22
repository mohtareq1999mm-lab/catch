<?php

declare(strict_types=1);

namespace App\Services\Shipment\Contracts;

use App\DTOs\Shipping\CarrierShipment;

interface CreatesCarrierShipments
{
    /** @param array<string,mixed> $parameters */
    public function createShipment(array $parameters): CarrierShipment;
}

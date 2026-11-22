<?php

declare(strict_types=1);

namespace App\Services\Shipment\Contracts;

use App\DTOs\Shipping\CarrierShipment;

interface CancelsCarrierShipments
{
    public function cancelShipment(string $externalShipmentId): CarrierShipment;
}

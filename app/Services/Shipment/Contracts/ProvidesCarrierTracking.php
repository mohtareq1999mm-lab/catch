<?php

declare(strict_types=1);

namespace App\Services\Shipment\Contracts;

use App\DTOs\Shipping\CarrierTrackingSnapshot;

interface ProvidesCarrierTracking
{
    public function tracking(string $externalShipmentId): CarrierTrackingSnapshot;
}

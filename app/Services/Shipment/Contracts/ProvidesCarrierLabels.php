<?php

declare(strict_types=1);

namespace App\Services\Shipment\Contracts;

use App\DTOs\Shipping\CarrierLabel;

interface ProvidesCarrierLabels
{
    public function label(string $externalShipmentId): CarrierLabel;
}

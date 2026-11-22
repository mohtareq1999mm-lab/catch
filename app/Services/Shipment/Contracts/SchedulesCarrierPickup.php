<?php

declare(strict_types=1);

namespace App\Services\Shipment\Contracts;

use App\DTOs\Shipping\CarrierPickup;

interface SchedulesCarrierPickup
{
    /** @param array<string,mixed> $parameters */
    public function schedulePickup(array $parameters): CarrierPickup;
}

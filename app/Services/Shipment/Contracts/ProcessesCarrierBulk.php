<?php

declare(strict_types=1);

namespace App\Services\Shipment\Contracts;

use App\DTOs\Shipping\CarrierBulkResult;

interface ProcessesCarrierBulk
{
    /** @param array<string,mixed> $parameters */
    public function processBulk(array $parameters): CarrierBulkResult;
}

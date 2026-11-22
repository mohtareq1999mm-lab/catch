<?php

declare(strict_types=1);

namespace App\Services\Shipment\Contracts;

use App\DTOs\Shipping\CarrierReturnLink;

/**
 * Link-only carrier return evidence. This contract never creates, updates, or
 * deletes a return and never introduces a second return lifecycle.
 */
interface LinksCarrierReturns
{
    /** @param array<string,mixed> $parameters */
    public function linkReturn(array $parameters): CarrierReturnLink;
}

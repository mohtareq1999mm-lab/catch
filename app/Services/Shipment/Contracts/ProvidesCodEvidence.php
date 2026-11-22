<?php

declare(strict_types=1);

namespace App\Services\Shipment\Contracts;

use App\DTOs\Shipping\CarrierCodEvidence;

/**
 * Financial evidence only. COD evidence can never complete a payment or
 * mutate an order, shipment, or fulfillment state.
 */
interface ProvidesCodEvidence
{
    /** @param array<string,mixed> $parameters */
    public function codEvidence(array $parameters): CarrierCodEvidence;
}

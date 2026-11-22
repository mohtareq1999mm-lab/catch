<?php

declare(strict_types=1);

namespace App\Services\Shipment\Contracts;

use App\DTOs\Shipping\CarrierQuote;

interface ProvidesCarrierQuotes
{
    /** @param array<string,mixed> $parameters */
    public function quote(array $parameters): CarrierQuote;
}

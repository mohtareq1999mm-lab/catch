<?php

declare(strict_types=1);

namespace App\Services\Shipment\Outbox;

use App\Models\Shipping\ShippingOutbox;

interface ShippingOutboxProcessor
{
    public function process(ShippingOutbox $outbox): ShippingOutboxResult;
}

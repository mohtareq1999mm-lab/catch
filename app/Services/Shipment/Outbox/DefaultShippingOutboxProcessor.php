<?php

declare(strict_types=1);

namespace App\Services\Shipment\Outbox;

use App\Models\Shipping\ShippingOutbox;

final class DefaultShippingOutboxProcessor implements ShippingOutboxProcessor
{
    public function process(ShippingOutbox $outbox): ShippingOutboxResult
    {
        return ShippingOutboxResult::unknown('shipping.outbox.processor_unconfigured');
    }
}

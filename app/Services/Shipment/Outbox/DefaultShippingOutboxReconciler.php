<?php

declare(strict_types=1);

namespace App\Services\Shipment\Outbox;

use App\Models\Shipping\ShippingOutbox;

final class DefaultShippingOutboxReconciler implements ShippingOutboxReconciler
{
    public function reconcile(ShippingOutbox $outbox): ShippingOutboxResult
    {
        return ShippingOutboxResult::unknown('shipping.outbox.reconciler_unconfigured');
    }
}

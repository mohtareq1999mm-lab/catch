<?php

declare(strict_types=1);

namespace App\Services\Shipment\Outbox;

use App\Models\Shipping\ShippingOutbox;

interface ShippingOutboxReconciler
{
    public function reconcile(ShippingOutbox $outbox): ShippingOutboxResult;
}

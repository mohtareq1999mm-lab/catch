<?php

declare(strict_types=1);

namespace App\Contracts\Shipping;

use App\Enums\Shipping\NotificationDeliveryResult;
use App\Models\Shipping\ShipmentNotification;

/** Decouples retry accounting from provider-specific channel delivery. */
interface ShipmentNotificationDispatcher
{
    public function __invoke(ShipmentNotification $notification): NotificationDeliveryResult;
}

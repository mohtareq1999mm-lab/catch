<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Contracts\Shipping\ShipmentNotificationDispatcher;
use App\Enums\Shipping\NotificationDeliveryResult;
use App\Models\Shipping\ShipmentNotification;

/**
 * Fail-closed default until a real channel adapter is bound.
 *
 * No delivery is attempted, so the result must not be reported as a safe
 * failure. Rows go to the ambiguous terminal state with an alert instead of
 * being blindly retried or silently exhausted.
 */
final class UnconfiguredShipmentNotificationDispatcher implements ShipmentNotificationDispatcher
{
    public function __invoke(ShipmentNotification $notification): NotificationDeliveryResult
    {
        return NotificationDeliveryResult::AMBIGUOUS;
    }
}

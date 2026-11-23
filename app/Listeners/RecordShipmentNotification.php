<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Shipment\ShipmentStatusChanged;
use App\Services\Shipment\ShipmentNotificationRecorder;

class RecordShipmentNotification
{
    public function __construct(private readonly ShipmentNotificationRecorder $recorder)
    {
    }

    public function handle(ShipmentStatusChanged $event): void
    {
        $this->recorder->record($event);
    }
}

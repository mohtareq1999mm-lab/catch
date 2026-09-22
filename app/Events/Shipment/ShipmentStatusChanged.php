<?php

namespace App\Events\Shipment;

use Marvel\Database\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShipmentStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        public string $oldStatus,
        public string $newStatus,
        public ?string $trackingNumber = null,
        public ?string $courierName = null,
        public ?array $locationData = null,
        public ?\DateTimeInterface $estimatedDelivery = null,
    ) {}
}

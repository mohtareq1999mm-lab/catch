<?php

namespace App\Events\Shipment;

use Marvel\Database\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EstimatedDeliveryChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Order $order,
        public ?\DateTimeInterface $oldEta,
        public \DateTimeInterface $newEta,
        public string $reason = 'courier_update',
    ) {}
}

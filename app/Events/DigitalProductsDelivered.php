<?php

namespace App\Events;

use App\Models\DigitalEntitlement;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Marvel\Database\Models\Order;

class DigitalProductsDelivered
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        public $entitlements,
    ) {}
}

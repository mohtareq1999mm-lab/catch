<?php

namespace App\Events\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FulfillmentCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Fulfillment $fulfillment
    ) {}
}

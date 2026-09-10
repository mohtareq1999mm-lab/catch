<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 0: emitted inside changeOrderStatus/reaper transactions. Event-level
 * deferral guarantees inventory-restore + notifications only fire on commit.
 */
class OrderCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public $order,
    ) {}
}

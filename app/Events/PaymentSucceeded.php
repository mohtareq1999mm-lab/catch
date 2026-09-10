<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 0 (P2): emitted inside payment-completion transactions on the
 * COD/Cashier paths. Laravel 10 ignores listener-level $afterCommit for
 * queued listeners; event-level ShouldDispatchAfterCommit is the supported
 * deferral mechanism — rollback now discards the whole fan-out.
 */
class PaymentSucceeded implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public $order,
    ) {}
}

<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 0 (P1): OrderCreated must never reach listeners while the emitting
 * checkout transaction is still open — a later rollback would orphan the
 * notification jobs. Laravel 10's supported mechanism is event-level
 * ShouldDispatchAfterCommit (listener-level $afterCommit is NOT honored by
 * this framework version's queued-listener dispatcher).
 */
class OrderCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public $order)
    {
    }
}

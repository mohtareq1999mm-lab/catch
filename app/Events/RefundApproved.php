<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Marvel\Database\Models\Refund;

/**
 * Phase 10 unification: THE canonical refund-approval event.
 *
 * Emitted once, inside the canonical RefundService approval transaction
 * (deferred to after commit). All approval side effects (inventory,
 * credit note, reviews, digital revocation, notifications, timelines)
 * listen to this event exactly once.
 *
 * Canonical location (PSR-4): this class used to live in
 * packages/marvel/src/Events/RefundApproved.php while declaring the
 * App\Events namespace, which only resolved through the optimized
 * classmap. It now lives where its namespace says it does.
 */
class RefundApproved implements ShouldDispatchAfterCommit
{
    public $refund;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param Refund $refund
     */
    public function __construct(Refund $refund)
    {
        $this->refund = $refund;
    }
}

<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Marvel\Database\Models\Refund;

/**
 * Phase 0: emitted inside the refund-approval transaction (infrastructure
 * exception to the "don't touch Marvel" rule — this class merely declares
 * the App\Events namespace). Event-level deferral keeps credit-note,
 * inventory-restore and entitlement-revocation atomic with approval state.
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

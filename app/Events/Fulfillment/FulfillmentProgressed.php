<?php

namespace App\Events\Fulfillment;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A fulfillment milestone with potential order-lifecycle meaning committed.
 *
 * Dispatched AFTER the producing transaction commits (event-level
 * ShouldDispatchAfterCommit, same contract as OrderCreated/PaymentSucceeded).
 * The listener converges the order via the authoritative Order Flow service;
 * this event never carries order writes itself.
 *
 * Milestones are informational (release, picking_started, picked,
 * ready_to_ship, shipped, delivered, shipment_progress). The listener
 * re-derives the aggregate order target from current state, so a lost or
 * duplicated event converges harmlessly on the next milestone.
 */
class FulfillmentProgressed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $orderId,
        public readonly string $milestone,
        public readonly ?int $fulfillmentId = null,
    ) {}
}

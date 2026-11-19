<?php

namespace App\Services\Order;

use App\Models\Fulfillment\Fulfillment;
use Marvel\Database\Models\Order;

/**
 * ADR 0004: the single statement of "may this order be marked delivered?".
 *
 * Two callers ask this question with different failure shapes:
 *
 *  - ShipmentService::maybeCompleteOrder() — auto-completion after the carrier
 *    delivers the last fulfillment. `false` means "stay as-is".
 *  - OrderTransitionService — the normal `delivered` transition. `false` maps
 *    to DELIVER_TRANSITION_BLOCKED; the audited force path bypasses it.
 *
 * Neither caller may restate the payment / fulfillment / delivered rule.
 * This class is a pure read-only predicate: no writes, no exceptions, no
 * events. Each caller keeps its own failure shape and wording.
 */
class DeliveryCompletionInvariant
{
    /**
     * "Paid" = the order's lifecycle payment status is success, or at least
     * one transaction settled as paid. The transaction arm covers orders
     * whose payment_status mirror lags a verified gateway transaction.
     */
    public function isPaid(Order $order): bool
    {
        if (($order->payment_status ?? null) === Order::PAYMENT_STATUS_SUCCESS) {
            return true;
        }

        return $order->transactions()->where('status', 'paid')->exists();
    }

    /**
     * Paid + at least one fulfillment + every fulfillment delivered.
     *
     * The fulfillment set is queried here rather than accepted as a
     * parameter so both callers share one definition and one query shape;
     * neither has a pre-loaded collection to hand over.
     */
    public function mayComplete(Order $order): bool
    {
        if (!$this->isPaid($order)) {
            return false;
        }

        $fulfillments = Fulfillment::where('order_id', $order->getKey());

        if (!(clone $fulfillments)->exists()) {
            return false;
        }

        return !(clone $fulfillments)->where('status', '!=', 'delivered')->exists();
    }
}

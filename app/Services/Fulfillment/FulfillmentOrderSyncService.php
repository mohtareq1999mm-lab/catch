<?php

namespace App\Services\Fulfillment;

use App\Events\Fulfillment\FulfillmentProgressed;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Shipment;
use App\Services\General\OrderService;
use App\Services\OrderFlow\OrderFlowService;
use App\Services\Shipment\ShipmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Order;

/**
 * Convergent Fulfillment → Order Flow synchronization.
 *
 * TRANSITION MATRIX (verified against Fulfillment::allowedTransitions and
 * OrderFlowService::allowsFlowTransition; local flow shown, international
 * adds export/in_transit/customs/local_carrier steps between packed/shipped
 * and shipped/out_for_delivery):
 *
 * | Fulfillment aggregate state                        | Order target     | Gate                                    |
 * |----------------------------------------------------|------------------|-----------------------------------------|
 * | any non-cancelled fulfillment exists               | processing       | immediate successor only                |
 * | all non-cancelled fulfillments picked+             | packed           | immediate successor only                |
 * | all non-cancelled fulfillments shipped/delivered   | shipped          | immediate successor only                |
 * | above + shipments at out_for_delivery/delivered    | out_for_delivery | immediate successor only                |
 * | completed + paid + every fulfillment delivered     | delivered        | ShipmentService::maybeCompleteOrder     |
 *
 * Deliberately UNMAPPED (no order meaning under current domain rules):
 * - assignment, picking-started, packing-started, label creation:
 *   operational detail, no distinct order step.
 * - fulfillment cancellation: must NEVER cancel the order (partial work may
 *   remain; order cancel cascades DOWN only, via changeOrderStatus).
 * - completed: payment milestone, owned exclusively by payment paths
 *   (PaymentCompletionService, markCodAsPaid/markCashierPaid). This service
 *   never targets it, so the F-1 payment guard can never be bypassed here.
 *
 * KNOWN LIMITATION L-01: paid (completed) orders cannot display intermediate
 * logistics steps — `completed` is not a member of the seeded flows, so
 * completed → packed/shipped is illegal by design. For paid orders every
 * intermediate nudge is skipped with a log and only the delivered step
 * applies. Changing that requires an Order Flow semantics decision (business
 * approval); this service never forces illegal transitions.
 *
 * LIMITATION L-02: international flows need manual customs steps
 * (export_processing, customs_*, local_carrier, in_transit) that WMS does not
 * perform. The walker stops at the first illegal step with a log; an admin
 * walks the customs steps manually and later milestones resume the sync.
 *
 * Aggregation: ALL non-cancelled fulfillments must reach a tier before the
 * order advances (a partial fulfillment never marks the whole order). A
 * cancelled fulfillment is excluded from the intermediate tiers; the final
 * delivered step keeps the existing strict rule (maybeCompleteOrder).
 *
 * Safety properties:
 * - Every order write goes through OrderService::changeOrderStatus (sole
 *   authority: flow validation, guards, history, mirrors, events) or
 *   ShipmentService::maybeCompleteOrder (which itself uses changeOrderStatus).
 *   No direct orders.status/flow_id/current_status_id writes anywhere here.
 * - Illegal transitions are caught, logged (visible skip), and stop the walk;
 *   earlier legal steps stay committed (each is a valid standalone state).
 * - Same-state repeats are no-ops (changeOrderStatus idempotency); concurrent
 *   or duplicate runs converge via the order row lock.
 * - No event loop: changeOrderStatus emits OrderStatusChanged/PaymentSucceeded
 *   only for completed targets (never targeted here); the cancelled cascade
 *   only runs for cancelled targets (never targeted here).
 */
class FulfillmentOrderSyncService
{
    /**
     * Walker ceiling: longest seeded flow walk (international, 12 steps).
     * Defensive only — the walk stops at the first non-advancing step.
     */
    private const MAX_STEPS = 12;

    public function __construct(
        private OrderService $orders,
        private OrderFlowService $flows,
        private ShipmentService $shipments,
    ) {}

    /**
     * Advance the order as far as aggregate fulfillment progress legally
     * allows. Never throws for illegal transitions (logged skip); unexpected
     * failures propagate for queue retry.
     *
     * @return array{advanced: string[], skipped: ?string, delivered: bool}
     */
    public function sync(int $orderId, string $reason): array
    {
        $order = Order::whereKey($orderId)->first();
        if ($order === null) {
            Log::warning('Fulfillment-order sync skipped: order missing', [
                'order_id' => $orderId, 'reason' => $reason,
            ]);

            return ['advanced' => [], 'skipped' => 'order_missing', 'delivered' => false];
        }

        if (in_array($order->status, ['delivered', 'cancelled'], true)) {
            return ['advanced' => [], 'skipped' => null, 'delivered' => $order->status === 'delivered'];
        }

        $advanced = [];
        $skipped = null;

        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            // Single fresh read per iteration (SR-01: fresh() can return
            // null on a concurrently removed row — fall back to last known).
            $fresh = $order->fresh() ?? $order;
            $target = $this->nextEligibleTarget($fresh);
            if ($target === null) {
                // Nothing legally proposable. If aggregate progress justifies
                // a step the flow forbids (L-01 paid intermediates, L-02
                // customs), record it as a visible skip instead of silently
                // converging: the next milestone re-evaluates.
                $blocked = $this->furthestEligibleTarget($fresh);
                $currentBlocked = (string) $fresh->status;
                if ($blocked !== null && $blocked !== $currentBlocked) {
                    Log::info('Fulfillment-order sync blocked by flow rules', [
                        'order_id' => $order->getKey(),
                        'from' => $currentBlocked,
                        'to' => $blocked,
                        'reason' => $reason,
                    ]);
                    $skipped = $currentBlocked . '->' . $blocked;
                }
                break;
            }

            $current = (string) $fresh->status;
            if ($current === $target) {
                break;
            }

            try {
                $mutated = $this->orders->changeOrderStatus(
                    null,
                    $target,
                    $order->getKey(),
                    true,
                    'Fulfillment progress: ' . $reason,
                    ['trigger' => 'fulfillment-sync', 'milestone' => $reason],
                );
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                // Illegal transition under current flow/payment rules (e.g.
                // L-01 paid-order intermediates, L-02 customs steps) or
                // missing required transition inputs (FlowInputValidation).
                // Expected: visible skip, never forced.
                Log::info('Fulfillment-order sync skipped illegal transition', [
                    'order_id' => $order->getKey(),
                    'from' => $current,
                    'to' => $target,
                    'reason' => $reason,
                    'error' => $e->getMessage(),
                ]);
                $skipped = $current . '->' . $target;
                break;
            }

            if ($mutated === false) {
                Log::warning('Fulfillment-order sync writer returned false', [
                    'order_id' => $order->getKey(), 'to' => $target, 'reason' => $reason,
                ]);
                $skipped = $current . '->' . $target;
                break;
            }

            $advanced[] = $target;
            $order = $mutated->fresh() ?? $order;

            if (in_array($order->status, ['delivered', 'cancelled'], true)) {
                break;
            }
        }

        // Final delivered step through the existing strict rule. Locked
        // per the maybeCompleteOrder contract (caller must hold the order
        // lock); a guard refusal is an expected skip, never a retryable
        // failure, so it is caught and logged like walker steps.
        try {
            $delivered = DB::transaction(function () use ($order) {
                Order::whereKey($order->getKey())->lockForUpdate()->first();
                return $this->shipments->maybeCompleteOrder($order->getKey());
            });
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            Log::info('Fulfillment-order sync skipped delivery completion', [
                'order_id' => $order->getKey(),
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
            $delivered = false;
        }

        return ['advanced' => $advanced, 'skipped' => $skipped, 'delivered' => $delivered];
    }

    /**
     * Announce a committed fulfillment milestone for order convergence.
     * Defensive: event dispatch must never break warehouse progress.
     * ShouldDispatchAfterCommit holds the event until the producer's
     * transaction commits; rollback discards it.
     */
    public function announce(int $orderId, string $milestone, ?int $fulfillmentId = null): void
    {
        try {
            event(new FulfillmentProgressed($orderId, $milestone, $fulfillmentId));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Furthest order step justified by aggregate progress, IGNORING flow
     * legality. Used only to report blocked steps visibly; the walker
     * proposes through nextEligibleTarget (legality-gated) instead.
     */
    private function furthestEligibleTarget(Order $order): ?string
    {
        $live = Fulfillment::where('order_id', $order->getKey())
            ->where('status', '!=', 'cancelled')
            ->pluck('status')
            ->all();

        if ($live === []) {
            return null;
        }

        if ($this->allAtLeast($live, ['picked', 'packing', 'ready_to_ship', 'shipped', 'delivered'])
            && $this->shipmentsAtOutForDelivery($order)
        ) {
            return 'out_for_delivery';
        }
        if ($this->allAtLeast($live, ['shipped', 'delivered'])) {
            return 'shipped';
        }
        if ($this->allAtLeast($live, ['picked', 'packing', 'ready_to_ship', 'shipped', 'delivered'])) {
            return 'packed';
        }

        return 'processing';
    }

    /**
     * Furthest order step justified by aggregate progress that is also the
     * order's immediate legal successor. Null when nothing new is eligible.
     */
    private function nextEligibleTarget(Order $order): ?string
    {
        $live = Fulfillment::where('order_id', $order->getKey())
            ->where('status', '!=', 'cancelled')
            ->pluck('status')
            ->all();

        if ($live === []) {
            return null;
        }

        $current = (string) $order->status;

        // Tier order matters: propose the furthest eligible step; the walker
        // still advances one legal successor at a time via allowsFlowTransition.
        $candidates = [];
        if ($this->allAtLeast($live, ['picked', 'packing', 'ready_to_ship', 'shipped', 'delivered'])
            && $this->shipmentsAtOutForDelivery($order)
        ) {
            $candidates[] = 'out_for_delivery';
        }
        if ($this->allAtLeast($live, ['shipped', 'delivered'])) {
            $candidates[] = 'shipped';
        }
        if ($this->allAtLeast($live, ['picked', 'packing', 'ready_to_ship', 'shipped', 'delivered'])) {
            $candidates[] = 'packed';
        }
        $candidates[] = 'processing';

        foreach ($candidates as $candidate) {
            if ($current === $candidate) {
                continue;
            }
            if ($this->flows->allowsFlowTransition($order, $current, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Every live fulfillment status is within the given reached set.
     *
     * @param string[] $live
     * @param string[] $reached
     */
    private function allAtLeast(array $live, array $reached): bool
    {
        foreach ($live as $status) {
            if (!in_array($status, $reached, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Out-for-delivery tier: fulfillments handed off AND courier progress
     * observed (at least one shipment out_for_delivery/delivered, none still
     * in a pre-handoff active state or awaiting carrier recovery).
     * Terminal history rows (cancelled, returned) neither satisfy nor
     * block — they are handled manually.
     */
    private function shipmentsAtOutForDelivery(Order $order): bool
    {
        $statuses = Shipment::where('order_id', $order->getKey())->pluck('status')->all();

        if ($statuses === []) {
            return false;
        }

        $observed = false;
        foreach ($statuses as $status) {
            // Pre-handoff active states block; failed_delivery needs manual
            // carrier recovery (retry → out_for_delivery, or returned), so it
            // blocks too rather than converging past it.
            if (in_array($status, ['pending', 'label_created', 'picked_up', 'in_transit', 'delayed', 'failed_delivery'], true)) {
                return false;
            }
            if (in_array($status, ['out_for_delivery', 'delivered'], true)) {
                $observed = true;
            }
        }

        return $observed;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Fulfillment\Fulfillment;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Inventory\OrderReservationService;
use Illuminate\Console\Command;
use Marvel\Database\Models\Order;

/**
 * Phase 3 automatic-recovery backstop: release every releasable order that
 * somehow has no (non-cancelled) fulfillment row.
 *
 * Catches whatever the event path missed: exhausted listener retries,
 * queue downtime windows, warehouse-added-later, and orders created
 * through paths that never dispatch the release triggers (e.g. legacy
 * admin creation). Runs hourly — the event listeners remain the primary
 * sub-minute path; this command is strictly recovery, never the hot path.
 *
 * Safety (mirrors the release guards, never weaker):
 * - Only orders releasable RIGHT NOW (same predicate family as
 *   FulfillmentService::assertReleasable): not cancelled/delivered;
 *   online requires payment-success + committed inventory; manual methods
 *   (cod / pay_at_cashier) require a secured reservation (committed under
 *   the commit-at-creation rule, active for legacy orders).
 * - Digital-only orders are skipped (entitlements path owns them).
 * - Release goes through releaseForOrder() with the deterministic
 *   automatic key, so a concurrent listener/admin release converges on
 *   the same row instead of duplicating (UNIQUE backstop stays final).
 * - Poison orders never abort the run: per-order try/catch, counted and
 *   reported at the end for ops follow-up.
 */
class ReleaseReadyFulfillments extends Command
{
    protected $signature = 'fulfillment:release-ready';

    protected $description = 'Release releasable orders missing a fulfillment (Phase 3 recovery backstop)';

    public function __construct(
        private FulfillmentService $fulfillments,
        private OrderReservationService $reservations,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $released = 0;
        $skipped = 0;
        $failed = 0;

        Order::query()
            ->whereNotIn('status', [Order::ORDER_STATUS_CANCELLED, Order::ORDER_STATUS_DELIVERED])
            ->where(function ($q) {
                $q->where(function ($online) {
                    $online->where('payment_method', 'online')
                        ->where('payment_status', Order::PAYMENT_STATUS_SUCCESS)
                        ->where('inventory_state', Order::INVENTORY_STATE_COMMITTED);
                })->orWhere(function ($manual) {
                    $manual->whereIn('payment_method', ['cod', 'pay_at_cashier'])
                        ->whereIn('inventory_state', [Order::INVENTORY_STATE_ACTIVE, Order::INVENTORY_STATE_COMMITTED]);
                });
            })
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('fulfillments')
                    ->whereColumn('fulfillments.order_id', 'orders.id')
                    ->where('fulfillments.status', '!=', 'cancelled');
            })
            ->orderBy('id')
            ->chunkById(200, function ($orders) use (&$released, &$skipped, &$failed) {
                foreach ($orders as $order) {
                    if (!$this->reservations->hasPhysicalLines($order)) {
                        $skipped++;
                        continue;
                    }

                    try {
                        $this->fulfillments->releaseForOrder(
                            $order,
                            null,
                            FulfillmentService::automaticReleaseKey((int) $order->id)
                        );
                        $released++;
                    } catch (\Throwable $e) {
                        // A poison order (or a lost race, which the next run
                        // heals) must never abort the whole sweep.
                        report($e);
                        $failed++;
                    }
                }
            });

        $this->info("Released {$released} fulfillment(s), skipped {$skipped} digital-only, {$failed} failed.");

        return self::SUCCESS;
    }
}

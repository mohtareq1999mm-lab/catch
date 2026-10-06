<?php

namespace App\Listeners;

use App\Events\RefundApproved;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Phase 7 (P7-7): restore inventory when a refund is approved.
 *
 * This is the intended business contract (event wired in both service
 * providers, timeline + notification siblings, dedicated test section):
 * a full refund approval returns the committed goods to stock.
 *
 * Exactly-once (D7-4): the ONLY restoration authority is
 * InventoryRestoreService::restore() (canonical COMMITTED → RESTORED
 * state claim, shared with the synchronous order-cancel path, this
 * listener's sibling RestoreProductInventory, and PaymentRefundService
 * full refunds). This listener performs no stock math of its own, so a
 * cancel-then-refund, refund-then-cancel, replay, or retry still restores
 * exactly once; losers no-op.
 *
 * Preserved contract from the original listener: cancelled orders are
 * skipped (their cancel path already owned restoration), gift-only
 * orders hold no restorable stock, and a missing/orphan refund order is
 * a graceful no-op.
 */
class RestoreInventoryOnRefund implements ShouldQueue
{
    public function __construct(
        private \App\Services\Inventory\InventoryRestoreService $inventoryRestore,
    ) {}

    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    public $afterCommit = true;

    public function handle(RefundApproved $event)
    {
        try {
            $order = $event->refund ? $event->refund->order : null;
            if (!$order || $order->status === 'cancelled') {
                return;
            }

            // Gift-only orders hold no restorable stock.
            $hasRestorable = $order->orderItems()
                ->where(function ($query) {
                    $query->whereNull('is_gift')->orWhere('is_gift', false);
                })
                ->exists();
            if (!$hasRestorable) {
                return;
            }

            $this->inventoryRestore->restore($order);
        } catch (Exception $th) {
            \Log::error('Error restoring inventory on refund: ' . $th->getMessage());
            throw $th;
        }
    }
}

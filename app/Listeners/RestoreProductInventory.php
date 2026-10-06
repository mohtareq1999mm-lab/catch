<?php

namespace App\Listeners;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;

class RestoreProductInventory implements ShouldQueue
{
    public function __construct(
        private \App\Services\Inventory\InventoryRestoreService $inventoryRestore,
    ) {}

    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    public $afterCommit = true;

    /**
     * Phase 7 (P7-2): exactly-once inventory restore.
     *
     * The ONLY restoration authority is InventoryRestoreService::restore()
     * (canonical COMMITTED → RESTORED state claim, shared with the
     * synchronous cancel path and the refund paths). This listener performs
     * no stock math of its own — re-running it, or running it alongside the
     * synchronous path in any order, restores exactly once; losers no-op.
     *
     * The legacy inventory_restored_at column is stamped as an
     * observability marker only — it is NOT an idempotency guard.
     */
    public function handle($event)
    {
        try {
            $order = $event->order;

            if (!$order || !$order->paid_at) {
                return;
            }

            $restored = $this->inventoryRestore->restore($order);

            if ($restored) {
                DB::transaction(function () use ($order) {
                    Order::whereKey($order->id)
                        ->whereNull('inventory_restored_at')
                        ->update(['inventory_restored_at' => now()]);
                });
            }
        } catch (Exception $th) {
            \Log::error('Error restoring product inventory: ' . $th->getMessage());
            throw $th;
        }
    }
}

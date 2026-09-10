<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Enums\ItemType;

/**
 * Handles inventory restoration for paid orders that are cancelled.
 *
 * When a paid order is cancelled, the inventory that was committed (sold) must be
 * restored back to stock. This is different from releasing a reservation, as the
 * stock_quantity needs to be incremented and sold_quantity needs to be decremented.
 *
 * State transition: committed -> restored
 */
class InventoryRestoreService
{
    /**
     * Restore committed inventory back to stock (paid order cancellation).
     * Only committed -> restored; any other state is a safe no-op.
     */
    public function restore(Order $order): bool
    {
        return $this->run(function () use ($order) {
            $claimed = Order::whereKey($order->id)
                ->where('inventory_state', Order::INVENTORY_STATE_COMMITTED)
                ->lockForUpdate()
                ->first();

            if (!$claimed) {
                return false; // not committed — never double-restore
            }

            $claimed->forceFill([
                'inventory_state' => Order::INVENTORY_STATE_RESTORED,
                'inventory_state_restored_at' => now(),
            ])->save();

            foreach ($this->aggregatePhysicalLines($claimed) as $line) {
                $stock = $this->lockStockRow($line['product_id'], $line['product_variant_id']);

                // Restore: increment stock_quantity, decrement sold_quantity
                $stock->stock_quantity = (int) ($stock->stock_quantity ?? 0) + $line['quantity'];
                $stock->sold_quantity = max(0, (int) ($stock->sold_quantity ?? 0) - $line['quantity']);
                $stock->in_stock = ((int) $stock->stock_quantity - (int) ($stock->reserved_quantity ?? 0)) > 0;
                $stock->save();
            }

            return true;
        });
    }

    private function run(\Closure $closure): mixed
    {
        // Compose with the caller's transaction when present; stay atomic alone otherwise.
        if (DB::transactionLevel() > 0) {
            return $closure();
        }

        return DB::transaction($closure);
    }

    /**
     * Aggregate physical quantities per inventory row.
     * Same logic as OrderReservationService to ensure consistency.
     */
    private function aggregatePhysicalLines(Order $order): \Illuminate\Support\Collection
    {
        return $order->orderItems()
            ->get()
            ->filter(function ($item) {
                if ($item->item_type === ItemType::DIGITAL) {
                    return false; // digital lines hold no physical inventory
                }

                $product = $item->product;
                if ($product && ($product->item_type ?? ItemType::PHYSICAL) === ItemType::DIGITAL) {
                    return false;
                }

                return true;
            })
            ->map(fn ($item) => [
                'product_id' => (int) $item->product_id,
                'product_variant_id' => $item->product_variant_id ? (int) $item->product_variant_id : null,
                'quantity' => max(0, (int) $item->product_quantity),
            ])
            ->filter(fn ($line) => $line['quantity'] > 0 && $line['product_id'] > 0)
            ->groupBy(fn ($line) => 'p'.$line['product_id'].'v'.($line['product_variant_id'] ?? 0))
            ->map(fn ($group) => [
                'product_id' => $group->first()['product_id'],
                'product_variant_id' => $group->first()['product_variant_id'],
                'quantity' => (int) $group->sum('quantity'),
            ])
            ->sortBy([['product_variant_id', 'asc'], ['product_id', 'asc']])
            ->values();
    }

    private function lockStockRow(int $productId, ?int $variantId): Product|ProductVariant
    {
        if ($variantId) {
            return ProductVariant::query()->whereKey($variantId)->lockForUpdate()->firstOrFail();
        }

        return Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
    }
}

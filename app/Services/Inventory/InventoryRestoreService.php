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
     * Phase 12: lines already partially restored via sellable returns
     * (order_items.restored_quantity) are restored only for their REMAINDER —
     * never double-credited.
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

            foreach ($this->remainingLines($claimed) as $line) {
                $this->restoreLineQuantity($claimed, $line);
            }

            return true;
        });
    }

    /**
     * Phase 12: restore SELLABLE returned units for specific order lines
     * (partial returns). Only while the order is committed; each line is
     * capped at (line quantity − already restored). Digital lines excluded.
     *
     * @param array<int, array{order_item_id: int, quantity: int}> $lines
     */
    public function restoreLines(Order $order, array $lines): int
    {
        return $this->run(function () use ($order, $lines) {
            $claimed = Order::whereKey($order->id)
                ->where('inventory_state', Order::INVENTORY_STATE_COMMITTED)
                ->lockForUpdate()
                ->first();

            if (!$claimed) {
                return 0;
            }

            $restored = 0;
            foreach ($lines as $line) {
                $item = $claimed->orderItems()->whereKey((int) $line['order_item_id'])->lockForUpdate()->first();
                if (!$item || !$this->isPhysicalItem($item)) {
                    continue;
                }
                $already = $this->restoredOf($item);
                $remaining = max(0, (int) $item->product_quantity - $already);
                $qty = min(max(0, (int) $line['quantity']), $remaining);
                if ($qty <= 0) {
                    continue;
                }
                $this->restoreLineQuantity($claimed, [
                    'product_id' => (int) $item->product_id,
                    'product_variant_id' => $item->product_variant_id ? (int) $item->product_variant_id : null,
                    'quantity' => $qty,
                ]);
                $item->forceFill(['restored_quantity' => $already + $qty])->save();
                $restored += $qty;
            }

            return $restored;
        });
    }

    /**
     * Per-row remaining quantities after partial sellable restores.
     */
    private function remainingLines(Order $order): \Illuminate\Support\Collection
    {
        $lines = $this->aggregatePhysicalLines($order);
        if (!\Illuminate\Support\Facades\Schema::hasColumn('order_products', 'restored_quantity')) {
            return $lines;
        }

        return $lines->map(function ($line) use ($order) {
            $restored = (int) $order->orderItems()
                ->where('product_id', $line['product_id'])
                ->when($line['product_variant_id'], fn ($q) => $q->where('product_variant_id', $line['product_variant_id']),
                    fn ($q) => $q->whereNull('product_variant_id'))
                ->sum('restored_quantity');
            $line['quantity'] = max(0, $line['quantity'] - $restored);

            return $line;
        })->filter(fn ($line) => $line['quantity'] > 0)->values();
    }

    private function restoreLineQuantity(Order $order, array $line): void
    {
        $stock = $this->lockStockRow($line['product_id'], $line['product_variant_id']);
        $stock->stock_quantity = (int) ($stock->stock_quantity ?? 0) + $line['quantity'];
        $stock->sold_quantity = max(0, (int) ($stock->sold_quantity ?? 0) - $line['quantity']);
        $stock->in_stock = ((int) $stock->stock_quantity - (int) ($stock->reserved_quantity ?? 0)) > 0;
        $stock->save();
    }

    private function restoredOf($item): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('order_products', 'restored_quantity')) {
            return 0;
        }

        return max(0, (int) ($item->restored_quantity ?? 0));
    }

    private function isPhysicalItem($item): bool
    {
        if (($item->item_type ?? null) === ItemType::DIGITAL) {
            return false;
        }
        $product = $item->product;

        return !$product || (($product->item_type ?? ItemType::PHYSICAL) !== ItemType::DIGITAL);
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

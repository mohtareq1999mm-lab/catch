<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Enums\ItemType;

/**
 * Single authoritative owner of the ORDER inventory reservation lifecycle.
 *
 * State machine (enforced via locked conditional claims — idempotent):
 *   none    -> active     reserveForOrder()
 *   active  -> committed  commit()
 *   active  -> released   release()
 *
 * order_products rows are the exact reservation source. The
 * products/product_variants.reserved_quantity counters remain the shared
 * availability index: available = stock_quantity - reserved_quantity.
 *
 * DIGITAL lines never touch physical counters (rule D1).
 *
 * Every public method is atomic on its own and safe to call inside an
 * existing transaction (no nested transaction is opened when one is active).
 */
class OrderReservationService
{
    /**
     * Reserve inventory for the order's physical lines.
     * Valid from state `none` (re-reserving an `active` order is a safe no-op).
     * Throws when available stock is insufficient — the caller's transaction
     * must roll back so no Order, no reservation and untouched CartItems remain.
     */
    public function reserveForOrder(Order $order): void
    {
        $this->run(function () use ($order) {
            $order = $this->lockOrder($order->id);

            if (!$order || $order->inventory_state !== Order::INVENTORY_STATE_NONE) {
                return; // already active/committed/released — nothing to do
            }

            $lines = $this->aggregatePhysicalLines($order);
            if ($lines->isEmpty()) {
                $this->markReserved($order);
                return;
            }

            foreach ($lines as $line) {
                $stock = $this->lockStockRow($line['product_id'], $line['product_variant_id']);

                $available = max(0, (int) ($stock->stock_quantity ?? 0) - (int) ($stock->reserved_quantity ?? 0));
                if ($available < $line['quantity']) {
                    throw new \App\Exceptions\InsufficientStockException(__(QUANTITY_EXCEEDS_STOCK));
                }
            }

            // Second pass performs the increments — rows are already locked in
            // deterministic order, so validation above cannot be invalidated.
            foreach ($lines as $line) {
                $stock = $this->lockStockRow($line['product_id'], $line['product_variant_id']);
                $stock->reserved_quantity = (int) ($stock->reserved_quantity ?? 0) + $line['quantity'];
                $stock->in_stock = ((int) $stock->stock_quantity - (int) $stock->reserved_quantity) > 0;
                $stock->save();
            }

            $this->markReserved($order);
        });
    }

    /**
     * Convert the reservation into a real deduction (payment success).
     * Only `active -> committed`; any other state is a safe no-op.
     */
    public function commit(Order $order): bool
    {
        return $this->run(function () use ($order) {
            $claimed = Order::whereKey($order->id)
                ->where('inventory_state', Order::INVENTORY_STATE_ACTIVE)
                ->lockForUpdate()
                ->first();

            if (!$claimed) {
                return false; // not active — never double-commit
            }

            $claimed->forceFill(['inventory_state' => Order::INVENTORY_STATE_COMMITTED])->save();

            foreach ($this->aggregatePhysicalLines($claimed) as $line) {
                $stock = $this->lockStockRow($line['product_id'], $line['product_variant_id']);
                $physicalQuantity = (int) ($stock->stock_quantity ?? 0);
                $stock->stock_quantity = max(0, $physicalQuantity - $line['quantity']);
                $stock->reserved_quantity = max(0, (int) ($stock->reserved_quantity ?? 0) - $line['quantity']);
                $stock->sold_quantity = (int) ($stock->sold_quantity ?? 0) + $line['quantity'];
                $stock->in_stock = ((int) $stock->stock_quantity - (int) $stock->reserved_quantity) > 0;
                $stock->save();
            }

            return true;
        });
    }

    /**
     * Release the reservation without selling (24h expiry / unpaid cancel).
     * Only `active -> released`; any other state is a safe no-op.
     */
    public function release(Order $order): bool
    {
        return $this->run(function () use ($order) {
            $claimed = Order::whereKey($order->id)
                ->where('inventory_state', Order::INVENTORY_STATE_ACTIVE)
                ->lockForUpdate()
                ->first();

            if (!$claimed) {
                return false; // not active — never double-release
            }

            $claimed->forceFill(['inventory_state' => Order::INVENTORY_STATE_RELEASED])->save();

            foreach ($this->aggregatePhysicalLines($claimed) as $line) {
                $stock = $this->lockStockRow($line['product_id'], $line['product_variant_id']);
                $stock->reserved_quantity = max(0, (int) ($stock->reserved_quantity ?? 0) - $line['quantity']);
                $stock->in_stock = ((int) ($stock->stock_quantity ?? 0) - (int) $stock->reserved_quantity) > 0;
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

    private function lockOrder(int $orderId): ?Order
    {
        return Order::whereKey($orderId)->lockForUpdate()->first();
    }

    /**
     * Aggregate physical quantities per inventory row so multiple lines
     * referencing the same product/variant are validated and reserved once.
     *
     * @return \Illuminate\Support\Collection<int, array{product_id:int, product_variant_id:?int, quantity:int}>
     */
    private function aggregatePhysicalLines(Order $order): \Illuminate\Support\Collection
    {
        $hasItemType = Schema::hasColumn('order_products', 'item_type');

        return $order->orderItems()
            ->get()
            ->filter(function ($item) use ($hasItemType) {
                if ($hasItemType && $item->item_type === ItemType::DIGITAL) {
                    return false; // D1 — digital lines hold no physical reservation
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

    private function markReserved(Order $order): void
    {
        $hours = self::timeoutHoursFor($order);

        $order->forceFill([
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
            'inventory_reserved_at' => now(),
            'reservation_expires_at' => $order->created_at ? $order->created_at->copy()->addHours($hours) : now()->addHours($hours),
        ])->save();
    }

    /**
     * Timeout matrix: Online and Pay-at-Cashier orders expire after 24 hours;
     * COD/Delivery orders expire after 7 days (never the same window).
     */
    public static function timeoutHoursFor(Order $order): int
    {
        if ($order->payment_method === 'cod') {
            return max(1, (int) config('payment.cod_order_timeout_hours', 24 * 7));
        }

        return max(1, (int) config('payment.order_timeout_hours', 24));
    }
}

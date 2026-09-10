<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Order;

/**
 * One-time (idempotent) migration of production data from the old
 * cart-owned reservation model to the order-owned model.
 *
 *  1. Detach cart-held reservations: every ACTIVE cart line still carrying a
 *     legacy reserved_quantity has that amount subtracted from the product /
 *     variant counter, then the line's reserved_quantity is zeroed.
 *     (Those units were never sold; users keep their cart items.)
 *  2. Re-home PENDING orders created before cutover (inventory_state=none):
 *     reserve their physical lines if stock allows, otherwise cancel + release.
 *  3. Label historical orders without touching counters:
 *       completed/delivered OR cancelled+paid  -> committed
 *       cancelled-unpaid                       -> released
 *  4. Drift audit: expected counters = sum of active reservations; report any
 *     mismatch, optionally correct with --fix.
 *
 * Every step is individually idempotent and safe to re-run. --dry-run never writes.
 */
class MigrateInventoryReservations extends Command
{
    protected $signature = 'inventory:migrate-reservations {--dry-run : Report only, write nothing} {--fix : Apply changes (omit for report-only)}';

    public function handle(): int
    {
        $write = ! $this->option('dry-run') && $this->option('fix');

        $this->info($write ? 'APPLYING reconciliation…' : 'REPORT ONLY (pass --fix to apply)');

        $detached = $this->detachCartReservations($write);
        $rehomed = $this->rehomePendingOrders($write);
        $labelled = $this->labelHistoricalOrders($write);

        $drift = $this->auditDrift($write);

        $this->info("Cart reservations detached : {$detached}");
        $this->info("Pending orders re-homed    : {$rehomed}");
        $this->info("Historical orders labelled : {$labelled}");
        $this->info('Counter drift rows         : ' . ($drift === 0 ? '0 — clean' : "{$drift} MISMATCH" . ($write ? ' (fixed)' : ' (NOT fixed)')));

        return self::SUCCESS;
    }

    private function detachCartReservations(bool $write): int
    {
        $count = 0;

        CartItem::query()
            ->where('reserved_quantity', '>', 0)
            ->whereHas('cart', fn ($q) => $q->where('status', 'active'))
            ->with(['product', 'productVariant'])
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($write, &$count) {
                foreach ($items as $item) {
                    DB::transaction(function () use ($item, $write, &$count) {
                        $locked = CartItem::whereKey($item->id)->lockForUpdate()->first();
                        if (!$locked || (int) $locked->reserved_quantity <= 0) {
                            return;
                        }

                        $qty = (int) $locked->reserved_quantity;

                        if ($write) {
                            if ($locked->product_variant_id) {
                                \Marvel\Database\Models\ProductVariant::query()
                                    ->whereKey($locked->product_variant_id)->lockForUpdate()->first()
                                    ?->decrement('reserved_quantity', $qty);
                            } elseif ($locked->product_id) {
                                \Marvel\Database\Models\Product::query()
                                    ->whereKey($locked->product_id)->lockForUpdate()->first()
                                    ?->decrement('reserved_quantity', max(0, $qty));
                            }
                            $locked->update(['reserved_quantity' => 0]);
                        }

                        $count++;
                    });
                }
            });

        return $count;
    }

    private function rehomePendingOrders(bool $write): int
    {
        $count = 0;

        Order::query()
            ->where('status', 'pending')
            ->where('inventory_state', Order::INVENTORY_STATE_NONE)
            ->orderBy('id')
            ->chunkById(200, function ($orders) use ($write, &$count) {
                /** @var \App\Services\Inventory\OrderReservationService $service */
                $service = app(\App\Services\Inventory\OrderReservationService::class);

                foreach ($orders as $order) {
                    DB::transaction(function () use ($order, $service, $write, &$count) {
                        $locked = Order::whereKey($order->id)->lockForUpdate()->first();
                        if (
                            !$locked
                            || $locked->status !== 'pending'
                            || $locked->inventory_state !== Order::INVENTORY_STATE_NONE
                        ) {
                            return;
                        }

                        try {
                            if ($write) {
                                // Throws on insufficient availability.
                                $hours = \App\Services\Inventory\OrderReservationService::timeoutHoursFor($locked);
                                $this->reserveLegacyOrder($locked, $hours);
                            } else {
                                $this->assertLegacyOrderFits($locked);
                            }
                        } catch (\Throwable $e) {
                            $this->warn("Order #{$locked->id}: insufficient stock during migration → will be cancelled+released.");
                            if ($write) {
                                $locked->forceFill(['inventory_state' => Order::INVENTORY_STATE_RELEASED])->save();
                                $locked->transactions()->where('status', 'pending')->update(['status' => 'failed']);
                                $locked->forceFill([
                                    'status' => 'cancelled',
                                    'payment_status' => Order::PAYMENT_STATUS_FAILED,
                                    'cancelled_at' => now(),
                                ])->save();
                            }
                        }

                        $count++;
                    });
                }
            });

        return $count;
    }

    private function assertLegacyOrderFits(Order $order): void
    {
        foreach ($this->physicalLines($order) as $line) {
            $stock = $line['row'];
            $available = max(0, (int) $stock->stock_quantity - (int) $stock->reserved_quantity);
            if ($available < $line['quantity']) {
                throw new \RuntimeException('insufficient');
            }
        }
    }

    private function reserveLegacyOrder(Order $order, int $hours): void
    {
        foreach ($this->physicalLines($order) as $line) {
            $stock = $line['fresh'];
            $stock->reserved_quantity = (int) $stock->reserved_quantity + $line['quantity'];
            $stock->in_stock = ((int) $stock->stock_quantity - (int) $stock->reserved_quantity) > 0;
            $stock->save();
        }

        $order->forceFill([
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
            'inventory_reserved_at' => now(),
            'reservation_expires_at' => $order->created_at->copy()->addHours($hours),
        ])->save();
    }

    private function labelHistoricalOrders(bool $write): int
    {
        $count = 0;

        Order::query()
            ->whereIn('status', ['completed', 'delivered', 'cancelled'])
            ->where('inventory_state', Order::INVENTORY_STATE_NONE)
            ->orderBy('id')
            ->chunkById(500, function ($orders) use ($write, &$count) {
                foreach ($orders as $order) {
                    $target = null;

                    if (in_array($order->status, ['completed', 'delivered'], true)) {
                        $target = Order::INVENTORY_STATE_COMMITTED;
                    } elseif ($order->status === 'cancelled') {
                        $target = $order->paid_at
                            ? Order::INVENTORY_STATE_COMMITTED   // paid then cancelled: restock handled by inventory_restored_at semantics
                            : Order::INVENTORY_STATE_RELEASED;   // never paid: nothing was ever deducted
                    }

                    if ($target === null) {
                        continue;
                    }

                    if ($write) {
                        Order::whereKey($order->id)
                            ->where('inventory_state', Order::INVENTORY_STATE_NONE)
                            ->update(['inventory_state' => $target]);
                    }
                    $count++;
                }
            });

        return $count;
    }

    private function auditDrift(bool $write): int
    {
        $expectedProducts = [];
        $expectedVariants = [];

        Order::query()
            ->where('inventory_state', Order::INVENTORY_STATE_ACTIVE)
            ->with('orderItems')
            ->chunkById(500, function ($orders) use (&$expectedProducts, &$expectedVariants) {
                foreach ($orders as $order) {
                    foreach ($order->orderItems as $item) {
                        if ($item->item_type === 'digital') {
                            continue;
                        }
                        $qty = (int) $item->product_quantity;
                        if ($item->product_variant_id) {
                            $expectedVariants[$item->product_variant_id] = ($expectedVariants[$item->product_variant_id] ?? 0) + $qty;
                        } else {
                            $expectedProducts[$item->product_id] = ($expectedProducts[$item->product_id] ?? 0) + $qty;
                        }
                    }
                }
            });

        $mismatches = 0;

        foreach ($expectedVariants as $variantId => $expected) {
            $variant = \Marvel\Database\Models\ProductVariant::find($variantId);
            if (!$variant || (int) $variant->reserved_quantity !== (int) $expected) {
                $mismatches++;
                $this->warn("DRIFT variant #{$variantId}: actual=" . ($variant?->reserved_quantity ?? 'missing') . " expected={$expected}");
                if ($write && $variant) {
                    $variant->forceFill(['reserved_quantity' => $expected])->save();
                }
            }
        }

        foreach ($expectedProducts as $productId => $expected) {
            $product = \Marvel\Database\Models\Product::withTrashed()->find($productId);
            if (!$product || (int) $product->reserved_quantity !== (int) $expected) {
                $mismatches++;
                $this->warn("DRIFT product #{$productId}: actual=" . ($product?->reserved_quantity ?? 'missing') . " expected={$expected}");
                if ($write && $product) {
                    $product->forceFill(['reserved_quantity' => $expected])->save();
                }
            }
        }

        return $mismatches;
    }

    /**
     * @return iterable<string, array{row: object, fresh: object, quantity: int}>
     */
    private function physicalLines(Order $order): iterable
    {
        $lines = collect();

        foreach ($order->orderItems()->get() as $item) {
            if ($item->item_type === 'digital') {
                continue;
            }
            $qty = (int) $item->product_quantity;
            if ($qty < 1 || !$item->product_id) {
                continue;
            }

            $key = 'v'.$item->product_variant_id.'p'.$item->product_id;
            $existing = $lines[$key]['quantity'] ?? 0;
            $lines[$key] = [
                'product_id' => (int) $item->product_id,
                'product_variant_id' => $item->product_variant_id ? (int) $item->product_variant_id : null,
                'quantity' => $existing + $qty,
            ];
        }

        foreach ($lines->sortBy([['product_variant_id', 'asc'], ['product_id', 'asc']]) as $line) {
            if ($line['product_variant_id']) {
                $row = \Marvel\Database\Models\ProductVariant::query()->whereKey($line['product_variant_id'])->lockForUpdate()->firstOrFail();
            } else {
                $row = \Marvel\Database\Models\Product::query()->whereKey($line['product_id'])->lockForUpdate()->firstOrFail();
            }

            yield 'lock' => ['row' => $row, 'fresh' => $row, 'quantity' => $line['quantity']];
        }
    }
}

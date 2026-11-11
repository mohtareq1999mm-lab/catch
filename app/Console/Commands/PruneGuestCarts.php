<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;

/**
 * Guest cart retention reaper (7-day inactivity by default).
 *
 * Safety rules:
 *  - Only guest carts (authenticated carts keep their existing "never expire"
 *    contract and are untouched).
 *  - A cart whose guest has a pending/processing order OR any pending payment
 *    attempt is PROTECTED and never deleted.
 *  - Bounded batches, row locks, idempotent (re-running deletes nothing new).
 */
class PruneGuestCarts extends Command
{
    protected $signature = 'guest:prune-carts {--dry-run : Report candidates without deleting}';

    protected $description = 'Delete expired guest carts, protecting carts linked to pending orders/payments';

    public function handle(): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('carts', 'guest_expires_at')) {
            $this->info('Guest cart schema absent — nothing to prune.');

            return self::SUCCESS;
        }

        $batchSize = max(1, (int) config('guest_checkout.prune_batch_size', 200));
        $maxBatches = 10;
        $pruned = 0;
        $protected = 0;
        $dryRun = (bool) $this->option('dry-run');

        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $candidates = Cart::query()
                ->whereNotNull('guest_id')
                ->whereNotNull('guest_expires_at')
                ->where('guest_expires_at', '<', now())
                ->whereNotExists(function ($q) {
                    $q->selectRaw(1)
                        ->from('orders')
                        ->whereColumn('orders.guest_id', 'carts.guest_id')
                        ->whereIn('orders.status', [
                            Order::ORDER_STATUS_PENDING,
                            Order::ORDER_STATUS_PROCESSING,
                        ]);
                })
                ->whereNotExists(function ($q) {
                    $q->selectRaw(1)
                        ->from('orders')
                        ->join('transactions', 'transactions.order_id', '=', 'orders.id')
                        ->whereColumn('orders.guest_id', 'carts.guest_id')
                        ->where('transactions.status', 'pending');
                })
                ->orderBy('id')
                ->limit($batchSize)
                ->get(['id', 'guest_id']);

            if ($candidates->isEmpty()) {
                break;
            }

            foreach ($candidates as $candidate) {
                $deleted = DB::transaction(function () use ($candidate, $dryRun, &$protected) {
                    $cart = Cart::query()->whereKey($candidate->id)->lockForUpdate()->first();

                    if (!$cart) {
                        return false; // already gone — idempotent
                    }

                    $blocked = Order::query()
                        ->where('guest_id', $cart->guest_id)
                        ->where(function ($q) {
                            $q->whereIn('status', [
                                Order::ORDER_STATUS_PENDING,
                                Order::ORDER_STATUS_PROCESSING,
                            ])->orWhereExists(function ($inner) {
                                $inner->selectRaw(1)
                                    ->from('transactions')
                                    ->whereColumn('transactions.order_id', 'orders.id')
                                    ->where('transactions.status', 'pending');
                            });
                        })
                        ->lockForUpdate()
                        ->exists();

                    if ($blocked) {
                        $protected++;

                        return false;
                    }

                    if ($dryRun) {
                        return true;
                    }

                    return (bool) $cart->delete();
                });

                if ($deleted) {
                    $pruned++;
                }
            }

            if ($candidates->count() < $batchSize) {
                break;
            }

            if ($pruned === 0 || $dryRun) {
                // No progress (all protected) or a dry-run: stop after the
                // first bounded batch instead of re-selecting the same rows.
                break;
            }
        }

        $this->info(sprintf(
            'Guest cart prune: pruned=%d protected=%d dry_run=%s',
            $pruned,
            $protected,
            $dryRun ? 'yes' : 'no'
        ));

        if (!$dryRun && \Illuminate\Support\Facades\Schema::hasTable('guest_idempotency_keys')) {
            $keyIds = \App\Models\GuestIdempotencyKey::query()
                ->where('created_at', '<', now()->subDays(7))
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

            if ($keyIds->isNotEmpty()) {
                \App\Models\GuestIdempotencyKey::query()->whereIn('id', $keyIds)->delete();
            }

            $this->info('Guest idempotency rows pruned: ' . $keyIds->count());
        }

        if (!$dryRun && \Illuminate\Support\Facades\Schema::hasTable('guest_identities')) {
            $identityIds = \App\Models\GuestIdentity::query()
                ->where('expires_at', '<', now()->subDays(30))
                ->whereDoesntHave('orders')
                ->whereDoesntHave('carts')
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

            if ($identityIds->isNotEmpty()) {
                \App\Models\GuestIdentity::query()->whereIn('id', $identityIds)->delete();
            }

            $this->info('Expired guest identities pruned: ' . $identityIds->count());
        }

        return self::SUCCESS;
    }
}

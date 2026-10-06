<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use App\Events\PaymentFailed;
use App\Services\General\OrderService;
use App\Services\Payment\PaymentGatewayFactory;

/**
 * Unpaid/unactioned-order reaper.
 *
 * Cancels pending orders whose ORDER-owned reservation has expired and
 * releases exactly that reservation. It NEVER touches carts or cart items —
 * inventory ownership lives entirely with the Order.
 *
 * Timeout matrix (set on reservation_expires_at at reservation time by
 * OrderReservationService::timeoutHoursFor(), not re-derived here):
 *   Online          -> 24 hours
 *   Pay at Cashier   -> 24 hours
 *   COD / Delivery   -> 7 days
 */
class CancelUnpaidOrders extends Command
{
    protected $signature = 'orders:cancel-unpaid';
    protected $description = 'Cancel pending orders whose inventory reservation expired (24h Online/Cashier, 7d COD/Delivery) and release it';

    private PaymentGatewayFactory $paymentGatewayFactory;

    public function __construct(
        PaymentGatewayFactory $paymentGatewayFactory,
    ) {
        parent::__construct();
        $this->paymentGatewayFactory = $paymentGatewayFactory;
    }

    public function handle(): int
    {
        $now = now();

        // Phase 3 addendum: COD/cashier commit at creation while still
        // unpaid, so expiry must cover COMMITTED pendings too — otherwise
        // their stock strands forever (the old ACTIVE-only filter would
        // never select them). The canonical writer restores committed rows
        // and releases active ones; paid rows stay excluded below.
        $query = Order::query()
            ->where('status', 'pending')
            ->whereIn('inventory_state', [Order::INVENTORY_STATE_ACTIVE, Order::INVENTORY_STATE_COMMITTED])
            ->whereNotNull('reservation_expires_at')
            ->where('reservation_expires_at', '<=', $now);

        if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'payment_status')) {
            $query->where(function ($q) {
                $q->whereNull('payment_status')
                    ->orWhere('payment_status', Order::PAYMENT_STATUS_PENDING);
            });
        }

        $orders = $query->orderBy('id')->cursor();

        $cancelledCount = 0;

        foreach ($orders as $order) {
            DB::transaction(function () use ($order, &$cancelledCount) {
                // Lock + re-check: payment success or an admin cancel may have
                // raced ahead of us while we waited for the lock.
                $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->first();

                if (
                    !$lockedOrder
                    || $lockedOrder->status !== 'pending'
                    || !in_array($lockedOrder->inventory_state, [Order::INVENTORY_STATE_ACTIVE, Order::INVENTORY_STATE_COMMITTED], true)
                ) {
                    return;
                }

                if ($lockedOrder->reservation_expires_at && $lockedOrder->reservation_expires_at->isFuture()) {
                    return;
                }

                // Defensive gateway pre-check: if the customer actually paid at
                // the gateway but the callback has not landed yet, do NOT cancel.
                if ($this->gatewayReportsPaid($lockedOrder)) {
                    return;
                }

                // P3-1 (D3): the ONLY lifecycle mutation is the canonical writer.
                // ORD-1 preserved via skipPromotionDecrement (never-paid expiry
                // cancels never decrement promotion usage); the never-paid
                // payment marker is preserved via markPaymentFailed; the
                // canonical writer owns reservation/coupon release, history,
                // invoice and lifecycle events. Payment-domain finalization
                // (pending transactions, PaymentFailed) stays here.
                try {
                    $mutated = app(OrderService::class)->changeOrderStatus(
                        null,
                        'cancelled',
                        $lockedOrder->id,
                        auditReason: 'Order cancelled due to reservation expiry',
                        auditContext: [
                            'reservation_expires_at' => $lockedOrder->reservation_expires_at?->toIso8601String(),
                            'trigger' => 'orders:cancel-unpaid',
                        ],
                        skipPromotionDecrement: true,
                        markPaymentFailed: true,
                    );
                } catch (\Throwable $e) {
                    // A poison order must never abort the whole reaper run.
                    report($e);

                    return;
                }

                if (!$mutated) {
                    return;
                }

                // Payment-domain finalization the canonical writer doesn't own:
                // fail every lingering pending transaction, then notify.
                $lockedOrder->transactions()
                    ->where('status', 'pending')
                    ->update(['status' => 'failed']);

                try {
                    event(new PaymentFailed($lockedOrder->refresh()));
                } catch (\Throwable $e) {
                    report($e);
                }

                $cancelledCount++;
            });
        }

        $this->info("Cancelled {$cancelledCount} unpaid order(s).");

        return self::SUCCESS;
    }

    /**
     * One-shot gateway verification for orders holding a pending ONLINE
     * transaction. COD / pay-at-cashier pendings are internal states, not
     * gateway payments, and are skipped.
     */
    private function gatewayReportsPaid(Order $order): bool
    {
        /** @var Transaction|null $pendingTransaction */
        $pendingTransaction = $order->transactions()
            ->where('status', 'pending')
            ->whereNotNull('gateway_transaction_id')
            ->whereNotIn('payment_method', ['cod', 'pay_at_cashier'])
            ->latest()
            ->first();

        if (!$pendingTransaction) {
            return false;
        }

        try {
            $gateway = $this->paymentGatewayFactory->make($pendingTransaction->payment_method);
        } catch (\Throwable $e) {
            return false; // unknown gateway — proceed with cancellation
        }

        try {
            $result = $gateway->verifyPayment($pendingTransaction->gateway_transaction_id);
        } catch (\Throwable $e) {
            report($e);

            return false; // verification unavailable — fail safe to cancellation path
        }

        return (bool) ($result->success ?? false) && $result->status === 'paid';
    }
}

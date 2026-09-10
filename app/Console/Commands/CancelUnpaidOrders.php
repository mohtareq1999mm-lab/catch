<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use App\Events\OrderCancelled;
use App\Events\OrderStatusChanged;
use App\Events\PaymentFailed;
use App\Services\Inventory\OrderReservationService;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Coupon\CouponReservationService;

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

    private OrderReservationService $orderReservationService;
    private PaymentGatewayFactory $paymentGatewayFactory;
    private CouponReservationService $couponReservationService;

    public function __construct(
        OrderReservationService $orderReservationService,
        PaymentGatewayFactory $paymentGatewayFactory,
        CouponReservationService $couponReservationService,
    ) {
        parent::__construct();
        $this->orderReservationService = $orderReservationService;
        $this->paymentGatewayFactory = $paymentGatewayFactory;
        $this->couponReservationService = $couponReservationService;
    }

    public function handle(): int
    {
        $now = now();

        $query = Order::query()
            ->where('status', 'pending')
            ->where('inventory_state', Order::INVENTORY_STATE_ACTIVE)
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
                    || $lockedOrder->inventory_state !== Order::INVENTORY_STATE_ACTIVE
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

                // Release THIS order's exact reservation first (active -> released).
                $this->orderReservationService->release($lockedOrder);

                // Release coupon reservation (Rule 14)
                $this->couponReservationService->release($lockedOrder);

                $cancelUpdateData = ['status' => 'cancelled'];
                if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'payment_status')) {
                    $cancelUpdateData['payment_status'] = Order::PAYMENT_STATUS_FAILED;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'fulfillment_status')) {
                    $cancelUpdateData['fulfillment_status'] = Order::FULFILLMENT_STATUS_CANCELLED;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'cancelled_at')) {
                    $cancelUpdateData['cancelled_at'] = now();
                }
                $previousStatus = $lockedOrder->status;
                $oldPaymentStatus = $lockedOrder->getOriginal('payment_status');
                $oldFulfillmentStatus = $lockedOrder->getOriginal('fulfillment_status');
                $lockedOrder->update($cancelUpdateData);

                // Record history for system expiry (immutable audit)
                try {
                    if (\Illuminate\Support\Facades\Schema::hasTable('order_status_history')) {
                        $lockedOrder->recordStatusChange(
                            oldStatus: $previousStatus,
                            newStatus: $lockedOrder->status,
                            changedBy: null,
                            changedByType: 'system',
                            notes: 'Order cancelled due to reservation expiry',
                            metadata: [
                                'reservation_expires_at' => $lockedOrder->reservation_expires_at?->toIso8601String(),
                                'old_payment_status' => $oldPaymentStatus,
                                'new_payment_status' => $cancelUpdateData['payment_status'] ?? $lockedOrder->payment_status,
                            ],
                            oldPaymentStatus: $oldPaymentStatus,
                            newPaymentStatus: $cancelUpdateData['payment_status'] ?? $lockedOrder->payment_status,
                            oldFulfillmentStatus: $oldFulfillmentStatus,
                            newFulfillmentStatus: $cancelUpdateData['fulfillment_status'] ?? $lockedOrder->fulfillment_status
                        );
                    }
                } catch (\Throwable $e) {
                    report($e);
                }

                // System-initiated pre-payment cancellation: the order was never
                // paid, so promotion usage must NOT be decremented. We bypass
                // changeOrderStatus() here but keep the audit events.
                event(new OrderStatusChanged($lockedOrder));

                $lockedOrder->transactions()
                    ->where('status', 'pending')
                    ->update(['status' => 'failed']);

                try {
                    event(new OrderCancelled($lockedOrder));
                } catch (\Throwable $e) {
                    report($e);
                }

                try {
                    event(new PaymentFailed($lockedOrder));
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

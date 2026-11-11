<?php

namespace App\Services\Guest;

use App\Exceptions\GuestCheckoutConflictException;
use App\Services\General\OrderService;
use App\Services\Payment\PaymentAttemptLifecycleService;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;

/**
 * Guest cancellation via a single-use secure link.
 *
 * The token is the credential: hash lookup under a row lock, policy check,
 * atomic used-mark and the CANONICAL status writer (changeOrderStatus). No
 * second cancellation authority exists.
 */
class GuestCancellationService
{
    public function __construct(
        private GuestOrderTokenService $tokenService,
        private OrderService $orderService,
        private PaymentAttemptLifecycleService $attemptLifecycle,
    ) {
    }

    /**
     * @throws GuestCheckoutConflictException
     */
    public function cancel(string $rawToken): Order
    {
        if (!$this->tokenService->isValidTokenFormat($rawToken)) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_invalid'), 404);
        }

        $hash = $this->tokenService->hash($rawToken);

        $order = DB::transaction(function () use ($hash) {
            /** @var Order|null $order */
            $order = Order::query()
                ->where('guest_cancellation_token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_invalid'), 404);
            }

            if ($order->guest_cancellation_token_used_at !== null) {
                throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_used'), 422);
            }

            $expiresAt = $order->guest_cancellation_token_expires_at;

            if ($expiresAt !== null && $expiresAt->isPast()) {
                throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_expired'), 422);
            }

            if (!$this->tokenService->isOrderCancellable($order)) {
                throw new GuestCheckoutConflictException(__('checkout.order_cancel_not_allowed'), 422);
            }

            // T-03 / R-POL-1: an unresolved attempt could still collect money.
            if ($this->attemptLifecycle->hasUnresolvedAttempt($order->id)) {
                throw new GuestCheckoutConflictException(__('checkout.order_payment_in_progress'), 409);
            }

            // Atomic single-use claim. If a concurrent request already used
            // the link, this update matches zero rows and the transaction
            // aborts before any status change.
            $used = Order::query()
                ->whereKey($order->id)
                ->whereNull('guest_cancellation_token_used_at')
                ->update(['guest_cancellation_token_used_at' => now()]);

            if ($used !== 1) {
                throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_used'), 422);
            }

            try {
                $cancelled = $this->orderService->changeOrderStatus(
                    null,
                    'cancelled',
                    $order->id,
                    auditReason: 'Guest single-use cancellation link',
                    auditContext: ['trigger' => 'guest_cancellation_link'],
                    // ORD-1: never decrement promotion usage for an order
                    // whose usage was never incremented (unpaid cancellation).
                    skipPromotionDecrement: $order->payment_status !== Order::PAYMENT_STATUS_SUCCESS,
                );
            } catch (\Throwable $e) {
                // Roll back the used-mark so a transient failure does not burn
                // the customer's only cancellation link. The status writer is
                // transactional; nothing was committed.
                throw $e;
            }

            if (!$cancelled) {
                throw new \RuntimeException(ERROR_CANCELLING_ORDER);
            }

            return $order->refresh();
        });

        return $order;
    }
}

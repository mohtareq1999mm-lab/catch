<?php

namespace App\Services\Guest;

use App\Exceptions\GuestCheckoutConflictException;
use App\Services\Payment\PaymentAttemptLifecycleService;
use Marvel\Database\Models\Order;

/**
 * The ONE cancellation policy gate (#81, spec #73).
 *
 * Whether a link may be exercised — send a code or cancel — is a single
 * business decision: the order must be cancellable under the customer policy
 * AND no payment attempt may still be able to collect money. Both the
 * code-request endpoint and the cancel endpoint consume this gate, so the
 * policy can never drift between them (a gate added to one but not the other
 * would let dead links spend an SMS or let money be collected around a
 * cancellation).
 *
 * Link-STATE checks (unknown/used/expired token) are NOT here: they belong to
 * the token hash lookup and are deliberately worded per surface.
 */
class GuestCancellationGate
{
    public function __construct(
        private GuestOrderTokenService $tokens,
        private PaymentAttemptLifecycleService $attempts,
    ) {
    }

    /**
     * @throws GuestCheckoutConflictException 422 not cancellable, 409 payment
     */
    public function assertCancellable(Order $order): void
    {
        if (!$this->tokens->isOrderCancellable($order)) {
            throw new GuestCheckoutConflictException(__('checkout.order_cancel_not_allowed'), 422);
        }

        // T-03 / R-POL-1: never issue a cancellation credential — and never
        // cancel — while money may still be collected.
        if ($this->attempts->hasUnresolvedAttempt($order->id)) {
            throw new GuestCheckoutConflictException(__('checkout.order_payment_in_progress'), 409);
        }
    }
}

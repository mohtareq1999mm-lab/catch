<?php

namespace App\Services\Guest;

use App\Exceptions\GuestCheckoutConflictException;
use App\Models\GuestIdentity;
use Marvel\Database\Models\Order;

/**
 * Session-bound re-delivery of the guest tracking link (#80, spec #73).
 *
 * The recovery path when the original SMS/email never arrived. It re-issues
 * a FRESH Tracking Token with the exact semantics of the existing re-issue
 * (hash rotation via GuestOrderTokenService; an admin revocation is never
 * resurrected) and hands delivery to the existing notifier verbatim — the
 * enable/disable configuration and every notifier skip condition decide
 * whether anything is sent.
 *
 * Security contract:
 *  - Bound to the verified Guest Identity: an order not owned by the session
 *    resolves to the same 404 as the order-detail endpoint (no IDOR oracle).
 *  - Unavailable to token-only callers by construction: the route carries
 *    guest.session, so no body token can ever reach this service.
 *  - No raw token in the response, storage or logs: the fresh credential
 *    travels only through SMS/email (never persisted, never returned).
 */
class GuestTrackingLinkResendService
{
    public function __construct(
        private GuestOrderTokenService $tokens,
        private GuestTrackingLinkNotifier $links,
    ) {
    }

    /**
     * @return array{order_id: int, tracking_expires_at: ?string}
     *
     * @throws GuestCheckoutConflictException 404 when the order is not owned
     *         by this guest session (or was claimed by a registered account);
     *         409 when admin-revoked tracking must not be resurrected.
     */
    public function resend(GuestIdentity $identity, ?Order $order): array
    {
        // The controller resolves ownership with the shared scoping query;
        // this re-assert never trusts a caller-provided order.
        if (
            !$order
            || (int) $order->guest_id !== (int) $identity->id
            || $order->user_id !== null
        ) {
            throw new GuestCheckoutConflictException(__('guest_checkout.order_not_found'), 404);
        }

        if ($order->guest_tracking_revoked_at !== null) {
            throw new GuestCheckoutConflictException(__('guest_checkout.tracking_revoked'), 409);
        }

        $trackingToken = $this->tokens->issueTrackingToken($order);

        if ($trackingToken === null) {
            // issueTrackingToken refuses exactly on admin revocation; the
            // guard above keeps the explicit message, this stays fail-safe.
            throw new GuestCheckoutConflictException(__('guest_checkout.tracking_revoked'), 409);
        }

        // Existing notifier verbatim: schedules after the response, honors
        // the enable/disable configuration and all skip conditions, and
        // never places the raw token in a log context.
        $this->links->notifyAfterResponse($order, $trackingToken);

        return [
            'order_id' => (int) $order->id,
            'tracking_expires_at' => $this->tokens->trackingExpiresAt($order)?->toIso8601String(),
        ];
    }
}

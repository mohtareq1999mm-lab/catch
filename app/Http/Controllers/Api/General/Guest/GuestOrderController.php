<?php

namespace App\Http\Controllers\Api\General\Guest;

use App\Http\Controllers\Controller;
use App\Exceptions\GuestCheckoutConflictException;
use App\Http\Resources\Order\OrderResource;
use App\Models\GuestIdentity;
use App\Services\Guest\GuestOrderTokenService;
use App\Services\Guest\GuestPaymentService;
use App\Services\Guest\GuestTrackingLinkResendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Order;
use Marvel\Traits\ApiResponse;

/**
 * Guest order confirmation / payment initiation / credential re-issue.
 * Every lookup is scoped to the verified guest identity (no order IDOR).
 */
class GuestOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private GuestPaymentService $payments,
        private GuestOrderTokenService $tokens,
        private \App\Services\Payment\PaymentAttemptLifecycleService $attempts,
        private GuestTrackingLinkResendService $resends,
    ) {
    }

    public function show(Request $request, GuestIdentity $identity, int $orderId): JsonResponse
    {
        $order = $this->ownedOrder($identity->id, $orderId);

        if (!$order) {
            return $this->apiResponse(__('guest_checkout.order_not_found'), 404, false);
        }

        $order->load(['orderItems.product', 'orderItems.productVariant']);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'order' => OrderResource::make($order),
            'can_cancel' => $this->tokens->isOrderCancellable($order)
                && !$this->attempts->hasUnresolvedAttempt($order->id),
        ]);
    }

    public function payment(Request $request, GuestIdentity $identity, int $orderId): JsonResponse
    {
        $order = $this->ownedOrder($identity->id, $orderId);

        if (!$order) {
            return $this->apiResponse(__('guest_checkout.order_not_found'), 404, false);
        }

        return $this->payments->initiate($request, $identity, $order);
    }

    /**
     * Re-issue public credentials to the OWNING browser only (confirmation
     * page refresh). Admin-revoked tracking is never resurrected.
     */
    public function accessTokens(Request $request, GuestIdentity $identity, int $orderId): JsonResponse
    {
        $order = $this->ownedOrder($identity->id, $orderId);

        if (!$order) {
            return $this->apiResponse(__('guest_checkout.order_not_found'), 404, false);
        }

        $trackingToken = $this->tokens->issueTrackingToken($order);
        $cancellationToken = $this->tokens->issueCancellationToken($order->refresh());

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'order_id' => $order->id,
            'tracking_token' => $trackingToken,
            'tracking_revoked' => $order->guest_tracking_revoked_at !== null,
            'cancellation_token' => $cancellationToken,
            'tracking_expires_at' => $this->tokens->trackingExpiresAt($order)?->toIso8601String(),
        ]);
    }

    /**
     * Session-bound re-delivery of the tracking link (#80): re-issues a fresh
     * Tracking Token (hash rotation, admin revocation never resurrected) and
     * re-delivers SMS/email through the existing notifier. Ownership uses the
     * same scoping as the other order surfaces; the workflow lives in the
     * service.
     */
    public function resendTrackingLink(GuestIdentity $identity, int $orderId): JsonResponse
    {
        try {
            $outcome = $this->resends->resend($identity, $this->ownedOrder($identity->id, $orderId));
        } catch (GuestCheckoutConflictException $e) {
            return $this->apiResponse($e->getMessage(), $e->httpStatus(), false);
        }

        return $this->apiResponse(__('guest_checkout.tracking_link_resent'), 200, true, $outcome);
    }

    private function ownedOrder(int $guestId, int $orderId): ?Order
    {
        return Order::query()
            ->whereKey($orderId)
            ->where('guest_id', $guestId)
            // A claimed order belongs to the registered account now; the
            // former guest cookie must no longer reach it.
            ->whereNull('user_id')
            ->first();
    }
}

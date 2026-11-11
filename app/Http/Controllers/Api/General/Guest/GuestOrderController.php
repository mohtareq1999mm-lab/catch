<?php

namespace App\Http\Controllers\Api\General\Guest;

use App\Http\Controllers\Controller;
use App\Http\Resources\Order\OrderResource;
use App\Services\Guest\GuestIdentityService;
use App\Services\Guest\GuestOrderTokenService;
use App\Services\Guest\GuestPaymentService;
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
        private GuestIdentityService $identities,
        private GuestPaymentService $payments,
        private GuestOrderTokenService $tokens,
        private \App\Services\Payment\PaymentAttemptLifecycleService $attempts,
    ) {
    }

    public function show(Request $request, int $orderId): JsonResponse
    {
        $identity = $this->identities->current($request);

        if (!$identity) {
            return $this->apiResponse(__('guest_checkout.session_required'), 401, false);
        }

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

    public function payment(Request $request, int $orderId): JsonResponse
    {
        $identity = $this->identities->current($request);

        if (!$identity) {
            return $this->apiResponse(__('guest_checkout.session_required'), 401, false);
        }

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
    public function accessTokens(Request $request, int $orderId): JsonResponse
    {
        $identity = $this->identities->current($request);

        if (!$identity) {
            return $this->apiResponse(__('guest_checkout.session_required'), 401, false);
        }

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

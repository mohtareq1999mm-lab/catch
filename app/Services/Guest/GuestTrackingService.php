<?php

namespace App\Services\Guest;

use App\Exceptions\GuestCheckoutConflictException;
use App\Models\OrderTrackingEvent;
use Marvel\Database\Models\Order;

/**
 * Intentionally minimal public tracking.
 *
 * Exposes order STATUS only: no customer name/phone/email, no address, no
 * items, no totals, no gateway/transaction internals, no admin notes.
 */
class GuestTrackingService
{
    public function __construct(private GuestOrderTokenService $tokenService)
    {
    }

    /**
     * @throws GuestCheckoutConflictException when the token is invalid,
     *         revoked, expired or cross-order.
     */
    public function track(string $rawToken): array
    {
        if (!$this->tokenService->isValidTokenFormat($rawToken)) {
            throw new GuestCheckoutConflictException(__('guest_checkout.tracking_link_invalid'), 404);
        }

        $order = $this->tokenService->findByTrackingToken($rawToken);

        if (!$order) {
            // Invalid, revoked, expired and foreign tokens are identical.
            throw new GuestCheckoutConflictException(__('guest_checkout.tracking_link_invalid'), 404);
        }

        return [
            'order' => [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'fulfillment_status' => $order->fulfillment_status,
                'created_at' => $order->created_at?->toIso8601String(),
                'last_updated_at' => $order->updated_at?->toIso8601String(),
            ],
            'timeline' => $this->publicTimeline($order),
            'tracking_expires_at' => $this->tokenService->trackingExpiresAt($order)?->toIso8601String(),
        ];
    }

    /**
     * Customer-visible events only. Metadata/actor/notes are deliberately
     * omitted from the public contract; translations that require
     * interpolation (amount, refund balance, cancellation code) are not
     * rendered at all, so a token-only caller can never read financial or
     * internal details.
     */
    private function publicTimeline(Order $order): array
    {
        return OrderTrackingEvent::query()
            ->forOrder($order->id)
            ->customerVisible()
            ->orderBy('event_timestamp')
            ->get()
            ->map(function (OrderTrackingEvent $event) {
                $description = $event->customer_description_key
                    ? (string) __($event->customer_description_key)
                    : '';

                if (preg_match('/:[a-zA-Z_]+/', $description)) {
                    $description = '';
                }

                return [
                    'timestamp' => $event->event_timestamp?->toIso8601String(),
                    'title' => $event->customer_label_key
                        ? __($event->customer_label_key)
                        : ucfirst(str_replace(['.', '_'], ' ', (string) $event->event_type)),
                    'description' => $description,
                ];
            })
            ->values()
            ->all();
    }
}

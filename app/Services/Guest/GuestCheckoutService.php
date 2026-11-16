<?php

namespace App\Services\Guest;

use App\Exceptions\CartEmptyException;
use App\Exceptions\GuestCheckoutConflictException;
use App\Http\Resources\Order\OrderResource;
use App\Models\GuestIdentity;
use App\Models\GuestIdempotencyKey;
use Illuminate\Http\Request;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;

/**
 * Guest checkout orchestration around the CANONICAL order creation path
 * (OrderService::addItemsInOrder). No second order authority is introduced.
 */
class GuestCheckoutService
{
    public function __construct(
        private GuestCartService $cartService,
        private GuestOrderTokenService $tokenService,
        private GuestIdempotencyService $idempotency,
        private \App\Services\General\OrderService $orderService,
        private GuestCartEligibility $eligibility,
    ) {
    }

    public function checkout(Request $request, GuestIdentity $guest): array
    {
        $this->assertGuestPaymentMethod($request);

        $cart = $this->cartService->findCart($guest);

        if (!$cart) {
            throw new CartEmptyException(__('guest_checkout.cart_not_found'));
        }

        $this->assertCartIsGuestEligible($cart);

        $idempotencyKey = $this->idempotencyKey($request);
        $claim = null;
        $replay = null;

        if ($idempotencyKey !== null) {
            $claimResult = $this->idempotency->claim(
                $guest->id,
                'checkout',
                $idempotencyKey,
                $request->all()
            );

            if ($claimResult['replay']) {
                return $this->replayResponse($claimResult['response'] ?? []);
            }

            $claim = $claimResult['claim'];
        }

        try {
            $order = $this->orderService->addItemsInOrder($request, $cart, $guest->id);

            if (!$order) {
                // A concurrent checkout won the pending-order race or a
                // validation authority rejected the request. Nothing new was
                // committed for this request.
                throw new GuestCheckoutConflictException(__('guest_checkout.checkout_in_progress'), 409);
            }

            $trackingToken = null;
            $cancellationToken = null;

            if ((int) $order->guest_id === (int) $guest->id) {
                $trackingToken = $this->tokenService->issueTrackingToken($order);
                $cancellationToken = $this->tokenService->issueCancellationToken($order);
            }

            $order->load(['orderItems.product', 'orderItems.productVariant']);

            $response = [
                'order' => OrderResource::make($order),
                'tracking_token' => $trackingToken,
                'cancellation_token' => $cancellationToken,
                'payment' => [
                    'method' => $order->payment_method,
                    'gateway' => $order->payment_gateway,
                    'next' => 'initiate_payment',
                ],
                'idempotent_replay' => false,
            ];

            if ($claim) {
                // Durable response replay WITHOUT raw credentials (hash-only
                // storage rule). Clients refresh credentials through the
                // guest-authenticated access-tokens endpoint if they lose them.
                $this->idempotency->complete($claim, [
                    'order_id' => $order->id,
                    'payment' => $response['payment'],
                ], $order->id);
            }

            return $response;
        } catch (\Throwable $e) {
            if ($claim) {
                $this->idempotency->fail($claim, $e->getMessage());
            }

            throw $e;
        }
    }

    private function replayResponse(array $stored): array
    {
        $order = isset($stored['order_id'])
            ? Order::query()->find((int) $stored['order_id'])
            : null;

        if (!$order) {
            throw new GuestCheckoutConflictException(__('guest_checkout.checkout_in_progress'), 409);
        }

        $order->load(['orderItems.product', 'orderItems.productVariant']);

        return [
            'order' => OrderResource::make($order),
            'tracking_token' => null,
            'cancellation_token' => null,
            'payment' => $stored['payment'] ?? [
                'method' => $order->payment_method,
                'gateway' => $order->payment_gateway,
                'next' => 'initiate_payment',
            ],
            'idempotent_replay' => true,
        ];
    }

    private function assertGuestPaymentMethod(Request $request): void
    {
        $method = $request->input('payment_method', 'online');

        if (!in_array($method, ['online', 'cod'], true)) {
            // Guest checkout never enables Cashier/POS or unknown methods.
            throw new GuestCheckoutConflictException(__('guest_checkout.payment_method_not_supported'), 422);
        }
    }

    /**
     * Defense in depth: every guest line is re-evaluated through the same
     * Eligibility verdict the add path uses, with a constant number of
     * queries, so a line injected or changed after add cannot reach the
     * order builder.
     */
    private function assertCartIsGuestEligible(Cart $cart): void
    {
        foreach ($this->eligibility->evaluateCart($cart) as $verdict) {
            if (!$verdict->allowed) {
                throw new GuestCheckoutConflictException(
                    __('guest_checkout.' . $verdict->reason->value),
                    422
                );
            }
        }
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key')
            ?? $request->input('idempotency_key');

        if (!is_string($key) || trim($key) === '') {
            return null;
        }

        $key = trim($key);

        if (strlen($key) > 191) {
            throw new GuestCheckoutConflictException(__('guest_checkout.invalid_idempotency_key'), 422);
        }

        return $key;
    }
}

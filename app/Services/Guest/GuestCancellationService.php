<?php

namespace App\Services\Guest;

use App\Enums\GuestCancellationLinkState;
use App\Exceptions\GuestCheckoutConflictException;
use App\Services\Order\OrderTransitionService;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;

/**
 * Guest cancellation via a single-use secure link plus a possession proof.
 *
 * The token is the first credential: hash lookup under a row lock, policy
 * check, atomic used-mark and the CANONICAL transition intent
 * (cancelByGuest). The OTP sent to the phone on the order (#81) is the
 * second factor — the link alone never cancels. No second cancellation
 * authority exists.
 */
class GuestCancellationService
{
    public function __construct(
        private GuestOrderTokenService $tokenService,
        private GuestCancellationLinkService $links,
        private OrderTransitionService $orderTransitions,
        private GuestCancellationGate $gate,
        private GuestCancellationOtpService $cancellationOtp,
    ) {
    }

    /**
     * @throws GuestCheckoutConflictException
     */
    public function cancel(string $rawToken, string $code): Order
    {
        $link = $this->links->resolve($rawToken);

        if ($link->order === null || $link->state === GuestCancellationLinkState::Unknown) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_invalid'), 404);
        }

        $hash = $this->tokenService->hash($rawToken);

        // Preflight (no lock): a dead or ineligible link never reaches the
        // OTP provider. The transaction below repeats these gates under the
        // row lock and stays the authority.
        $candidate = $link->order;
        $this->assertCancellable($candidate);

        $proof = $this->cancellationOtp->verify($rawToken, $code, $candidate);

        $order = DB::transaction(function () use ($hash, $proof) {
            /** @var Order|null $order */
            $order = Order::query()
                ->where('guest_cancellation_token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_invalid'), 404);
            }

            $this->assertCancellable($order);

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
                $cancelled = $this->orderTransitions->cancelByGuest($order, $proof);
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

    /**
     * The single gate chain shared by the preflight and the locked
     * transaction. Order matters: link state first, then policy, then money.
     * The state itself comes from the liveness module — this surface only
     * words the dead states.
     *
     * @throws GuestCheckoutConflictException
     */
    private function assertCancellable(Order $order): void
    {
        $state = $this->links->stateFor($order);

        if ($state === GuestCancellationLinkState::Used) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_used'), 422);
        }

        if ($state === GuestCancellationLinkState::Expired) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_expired'), 422);
        }

        $this->gate->assertCancellable($order);
    }
}

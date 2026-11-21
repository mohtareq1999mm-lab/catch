<?php

namespace App\Services\Guest;

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
        if (!$this->tokenService->isValidTokenFormat($rawToken)) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_invalid'), 404);
        }

        $hash = $this->tokenService->hash($rawToken);

        // Preflight (no lock): a dead or ineligible link never reaches the
        // OTP provider. The transaction below repeats these gates under the
        // row lock and stays the authority.
        $lockedOrder = $this->resolveByTokenHash($hash);
        $this->assertCancellable($lockedOrder);

        $proof = $this->cancellationOtp->verify($rawToken, $code, $lockedOrder);

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
     * @throws GuestCheckoutConflictException
     */
    private function resolveByTokenHash(string $hash): Order
    {
        /** @var Order|null $order */
        $order = Order::query()
            ->where('guest_cancellation_token_hash', $hash)
            ->first();

        if (!$order) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_invalid'), 404);
        }

        return $order;
    }

    /**
     * The single gate chain shared by the preflight and the locked
     * transaction. Order matters: link state first, then policy, then money.
     *
     * @throws GuestCheckoutConflictException
     */
    private function assertCancellable(Order $order): void
    {
        // Link-STATE checks stay here (worded per surface); the POLICY gate
        // is the one shared with the code-request endpoint (#81 review).
        if ($order->guest_cancellation_token_used_at !== null) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_used'), 422);
        }

        $expiresAt = $order->guest_cancellation_token_expires_at;

        if ($expiresAt !== null && $expiresAt->isPast()) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_expired'), 422);
        }

        $this->gate->assertCancellable($order);
    }
}

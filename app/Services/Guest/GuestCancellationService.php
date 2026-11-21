<?php

namespace App\Services\Guest;

use App\Enums\GuestCancellationLinkState;
use App\Exceptions\GuestCheckoutConflictException;
use App\Services\Order\OrderTransitionService;
use App\ValueObjects\GuestCancellationLink;
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

        // Preflight (no lock): a dead or ineligible link never reaches the
        // OTP provider. The transaction below repeats these gates under the
        // row lock and stays the authority.
        $order = $this->liveOrder($link);
        $this->gate->assertCancellable($order);

        $proof = $this->cancellationOtp->verify($rawToken, $code, $order);

        return DB::transaction(function () use ($link, $proof) {
            /** @var Order|null $order */
            $order = Order::query()
                ->where('guest_cancellation_token_hash', $link->hash)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_invalid'), 404);
            }

            // Re-derived from the LOCKED row: a concurrent winner's used-mark
            // is visible here even though the preflight predates it.
            $this->assertLiveState($this->links->stateFor($order));
            $this->gate->assertCancellable($order);

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
    }

    /**
     * Preflight wording of a resolved link. Unknown (malformed, missing or
     * unmatched) is the same no-oracle invalid answer this surface always
     * gave; used and expired keep their distinct 422 reasons.
     *
     * @throws GuestCheckoutConflictException
     */
    private function liveOrder(GuestCancellationLink $link): Order
    {
        if ($link->order === null || $link->state === GuestCancellationLinkState::Unknown) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_invalid'), 404);
        }

        $this->assertLiveState($link->state);

        return $link->order;
    }

    /**
     * The single state wording for this surface. The state itself always
     * comes from the liveness module — this method never re-reads the token
     * columns. Unknown is refused as an invalid link: a row that never owned
     * a link cannot be cancelled through one.
     *
     * @throws GuestCheckoutConflictException
     */
    private function assertLiveState(GuestCancellationLinkState $state): void
    {
        if ($state === GuestCancellationLinkState::Unknown) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_invalid'), 404);
        }

        if ($state === GuestCancellationLinkState::Used) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_used'), 422);
        }

        if ($state === GuestCancellationLinkState::Expired) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_expired'), 422);
        }
    }
}

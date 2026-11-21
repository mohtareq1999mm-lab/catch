<?php

namespace App\Services\Guest;

use App\Enums\GuestCancellationLinkState;
use App\ValueObjects\GuestCancellationLink;
use Marvel\Database\Models\Order;

/**
 * The ONE cancellation-link liveness rule (#104).
 *
 * A raw token resolves to unknown, live, used or expired. Surfaces keep their
 * own wording — the OTP-request surface folds every dead state into its
 * no-oracle 404, the cancel surface keeps distinct 422 reasons — but neither
 * re-derives the state from the token columns.
 */
class GuestCancellationLinkService
{
    public function __construct(private GuestOrderTokenService $tokens)
    {
    }

    /**
     * Resolve a raw token. Unknown covers malformed, missing and unmatched
     * tokens; the order is returned for every matched row, dead or alive.
     */
    public function resolve(string $rawToken): GuestCancellationLink
    {
        if (!$this->tokens->isValidTokenFormat($rawToken)) {
            return new GuestCancellationLink(GuestCancellationLinkState::Unknown, null);
        }

        $hash = $this->tokens->hash($rawToken);

        $order = Order::query()
            ->where('guest_cancellation_token_hash', $hash)
            ->first();

        if (!$order) {
            return new GuestCancellationLink(GuestCancellationLinkState::Unknown, null);
        }

        return new GuestCancellationLink($this->stateFor($order), $order, $hash);
    }

    /**
     * Liveness of an already-loaded row (e.g. under the cancellation lock).
     *
     * Precondition: the row was matched by its stored hash (resolve(), or the
     * locked lookup by the same hash). A row with no stored hash never owned a
     * link, so its liveness is unknown rather than live — a caller must not
     * pass an arbitrary order fetched by id and treat the answer as a grant.
     *
     * Used wins over expired: the link was consumed first.
     */
    public function stateFor(Order $order): GuestCancellationLinkState
    {
        if ($order->guest_cancellation_token_hash === null) {
            return GuestCancellationLinkState::Unknown;
        }

        if ($order->guest_cancellation_token_used_at !== null) {
            return GuestCancellationLinkState::Used;
        }

        $expiresAt = $order->guest_cancellation_token_expires_at;

        if ($expiresAt !== null && $expiresAt->isPast()) {
            return GuestCancellationLinkState::Expired;
        }

        return GuestCancellationLinkState::Live;
    }
}

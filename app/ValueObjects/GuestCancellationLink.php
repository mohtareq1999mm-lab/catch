<?php

namespace App\ValueObjects;

use App\Enums\GuestCancellationLinkState;
use Marvel\Database\Models\Order;

/**
 * A resolved guest cancellation link: its liveness state, the matched order
 * (present for live, used and expired links; null for unknown) and the stored
 * token hash (so a caller that already resolved the link never re-hashes the
 * raw token; null for unknown).
 */
final class GuestCancellationLink
{
    public function __construct(
        public readonly GuestCancellationLinkState $state,
        public readonly ?Order $order,
        public readonly ?string $hash = null,
    ) {
    }

    public function isLive(): bool
    {
        return $this->state === GuestCancellationLinkState::Live;
    }
}

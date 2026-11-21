<?php

namespace App\ValueObjects;

use App\Enums\GuestCancellationLinkState;
use Marvel\Database\Models\Order;

/**
 * A resolved guest cancellation link: its liveness state plus the matched
 * order (present for live, used and expired links; null for unknown).
 */
final class GuestCancellationLink
{
    public function __construct(
        public readonly GuestCancellationLinkState $state,
        public readonly ?Order $order,
    ) {
    }

    public function isLive(): bool
    {
        return $this->state === GuestCancellationLinkState::Live;
    }
}

<?php

declare(strict_types=1);

namespace App\Enums\Settlement;

/**
 * #182: finance-controlled acceptance lifecycle for settlement evidence.
 *
 * Acceptance is never inferred from import time or arrival order. Only an
 * explicit finance action moves a statement out of `pending`.
 */
enum SettlementAcceptanceStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case SUPERSEDED = 'superseded';
}

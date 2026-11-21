<?php

namespace App\Enums;

/**
 * Liveness of a guest cancellation link (#104).
 *
 * Computed once by GuestCancellationLinkService; surfaces map it to their own
 * wording without re-reading the token columns.
 */
enum GuestCancellationLinkState: string
{
    /** Malformed, missing or unmatched token. */
    case Unknown = 'unknown';

    /** Matched, unused and not expired. */
    case Live = 'live';

    /** Matched, already consumed. */
    case Used = 'used';

    /** Matched, unused, past its expiry. */
    case Expired = 'expired';
}

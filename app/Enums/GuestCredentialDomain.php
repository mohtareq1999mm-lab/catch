<?php

namespace App\Enums;

/**
 * Keyed-HMAC domains for guest credentials (ADR 0001).
 *
 * The domain value is prepended to the HMAC message, so the same raw value
 * produces a different digest per domain. Only domains introduced with an
 * explicit prefix carry one: `Legacy` must stay empty because browser
 * identity cookies and order credentials (tracking, cancellation, claim)
 * were persisted under that derivation before this enum existed. Adding a
 * prefix there would invalidate every live cookie and every issued
 * tracking/cancellation link — that is a data migration, not a refactor.
 */
enum GuestCredentialDomain: string
{
    /** Pre-domain derivation shared by browser cookies and order credentials. */
    case Legacy = '';

    /** Native mobile Bearer transport. */
    case Mobile = 'guest-mobile|';

    /** Double-submit CSRF token derived from the identity id. */
    case Csrf = 'guest-csrf|';
}

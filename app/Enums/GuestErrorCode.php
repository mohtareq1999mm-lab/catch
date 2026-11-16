<?php

namespace App\Enums;

/**
 * Machine-readable guest error codes emitted alongside the localized message
 * (A10). Deliberately only the two approved values.
 */
enum GuestErrorCode: string
{
    case CREDENTIAL_CONFLICT = 'credential_conflict';
    case MOBILE_SESSION_EXISTS = 'mobile_session_exists';
}

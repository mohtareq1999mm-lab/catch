<?php

namespace App\Contracts;

use App\ValueObjects\VerifiedPhoneIdentity;
use Marvel\Database\Models\User;

/**
 * Verification seam for guest order claiming.
 *
 * PRODUCTION BINDING IS THE NULL IMPLEMENTATION until OTP is ready. A real
 * implementation must verify a server-side one-time password/challenge and
 * return proof bound to the SAME authenticated user + normalized phone.
 *
 * Returning null means "not verified"; callers must fail closed.
 */
interface GuestPhoneVerificationContract
{
    public function verify(User $user, string $verificationReference, string $phone): ?VerifiedPhoneIdentity;
}

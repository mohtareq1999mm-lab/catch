<?php

namespace App\Services\Guest;

use App\Contracts\GuestPhoneVerificationContract;
use App\ValueObjects\VerifiedPhoneIdentity;
use Marvel\Database\Models\User;

/**
 * Fail-closed default verifier.
 *
 * OTP is not ready. This implementation NEVER verifies a phone, so the claim
 * service cannot be activated by uncommenting a call or flipping a config
 * flag alone: a real verified binding is also required.
 */
final class NullGuestPhoneVerification implements GuestPhoneVerificationContract
{
    public function verify(User $user, string $verificationReference, string $phone): ?VerifiedPhoneIdentity
    {
        return null;
    }
}

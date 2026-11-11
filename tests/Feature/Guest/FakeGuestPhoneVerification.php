<?php

namespace Tests\Feature\Guest;

use App\Contracts\GuestPhoneVerificationContract;
use App\ValueObjects\VerifiedPhoneIdentity;
use Marvel\Database\Models\User;

/**
 * Test-only verifier. Explicitly bound per test; production default is the
 * Null verifier.
 */
final class FakeGuestPhoneVerification implements GuestPhoneVerificationContract
{
    public function __construct(
        private int $userId,
        private string $phone,
        private string $reference,
    ) {
    }

    public function verify(User $user, string $verificationReference, string $phone): ?VerifiedPhoneIdentity
    {
        $normalize = fn ($value) => preg_replace('/\D+/', '', (string) $value) ?? '';

        if (
            (int) $user->id !== $this->userId
            || $verificationReference !== $this->reference
            || $normalize($phone) === ''
            || !hash_equals($normalize($this->phone), $normalize($phone))
        ) {
            return null;
        }

        return new VerifiedPhoneIdentity(
            userId: (int) $user->id,
            phone: $normalize($phone),
            reference: $verificationReference,
            verifiedAt: now(),
        );
    }
}

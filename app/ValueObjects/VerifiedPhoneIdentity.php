<?php

namespace App\ValueObjects;

use Carbon\CarbonInterface;

/**
 * Proof that an OTP (or equally strong verifier) confirmed the phone belongs
 * to the authenticated user. Only a real GuestPhoneVerificationContract
 * implementation may produce this in production.
 */
final class VerifiedPhoneIdentity
{
    public function __construct(
        public readonly int $userId,
        public readonly string $phone,
        public readonly string $reference,
        public readonly CarbonInterface $verifiedAt,
    ) {
    }

    /** Digits-only comparison form (no leading +, spaces or punctuation). */
    public function normalizedPhone(): string
    {
        return preg_replace('/\D+/', '', $this->phone) ?? '';
    }
}

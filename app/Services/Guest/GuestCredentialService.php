<?php

namespace App\Services\Guest;

use App\Enums\GuestCredentialDomain;

/**
 * One source of truth for guest credential primitives (#103).
 *
 * Owns:
 *  - the opaque-secret format (length bounds + shape pattern),
 *  - the CSPRNG mint,
 *  - the keyed HMAC with explicit domain separation (ADR 0001),
 *  - the credential-retirement column sets for claim, tracking revocation and
 *    mobile revocation, so those transitions have one definition.
 *
 * Raw secrets are never persisted or logged by this class; callers hand them
 * straight to the client and persist only the hash.
 */
class GuestCredentialService
{
    /** Minimum accepted raw credential length. */
    public const MIN_LENGTH = 20;

    /** Maximum accepted raw credential length. */
    public const MAX_LENGTH = 120;

    /** The ONE shape gate for every guest credential. */
    public const PATTERN = '/^[A-Za-z0-9_-]{' . self::MIN_LENGTH . ',' . self::MAX_LENGTH . '}$/';

    /** 32 bytes -> 43 URL-safe characters, always inside the pattern bounds. */
    private const MINT_BYTES = 32;

    /** Mint an opaque, URL-safe secret (256 bits of entropy). */
    public function mint(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::MINT_BYTES)), '+/', '-_'), '=');
    }

    /**
     * Keyed HMAC-SHA256 with domain separation. `Legacy` keeps every hash
     * persisted before this module resolving unchanged; new call sites must
     * name their domain explicitly.
     */
    public function hash(string $raw, GuestCredentialDomain $domain): string
    {
        return hash_hmac('sha256', $domain->value . $raw, (string) config('app.key'));
    }

    /** Shape gate; rejects Sanctum-shaped customer tokens ("id|…") and garbage. */
    public function isValidFormat(?string $raw): bool
    {
        return is_string($raw) && preg_match(self::PATTERN, $raw) === 1;
    }

    /**
     * Credential columns retired when a guest order is claimed by a user.
     * The claim path composes this into its single atomic UPDATE so the
     * credential state and the ownership change commit together.
     *
     * @return array<string, mixed>
     */
    public function claimRetirement(): array
    {
        return [
            'guest_cancellation_token_hash' => null,
            'guest_cancellation_token_used_at' => now(),
            'guest_tracking_token_hash' => null,
            'guest_tracking_revoked_at' => now(),
        ];
    }

    /**
     * Credential columns for an explicit admin tracking revocation.
     *
     * @return array<string, mixed>
     */
    public function trackingRevocation(): array
    {
        return [
            'guest_tracking_token_hash' => null,
            'guest_tracking_revoked_at' => now(),
        ];
    }

    /**
     * Credential columns for mobile-session revocation.
     *
     * @return array<string, mixed>
     */
    public function mobileRevocation(): array
    {
        return [
            'mobile_token_hash' => null,
            'revoked_at' => now(),
        ];
    }
}

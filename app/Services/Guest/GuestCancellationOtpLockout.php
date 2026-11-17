<?php

namespace App\Services\Guest;

use App\Exceptions\GuestCheckoutConflictException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Brute-force lockout for the guest-cancellation confirm step (design D8).
 *
 * The framework rate limiter over the cache store counts FAILED CODE
 * attempts per cancellation token, keyed by the token's keyed HMAC hash —
 * the same digest the persistence layer uses — so the raw credential never
 * lands in a cache key. Five failures exhaust the budget → HTTP 429 until
 * the window expires (each failure re-arms it, mirroring the login-OTP
 * guard); a successful verification clears the budget.
 *
 * Policy failures (invalid / used / expired / non-cancellable / unresolved
 * payment attempt / no phone) NEVER consume an attempt — only the OTP
 * verification outcome does.
 */
final class GuestCancellationOtpLockout
{
    /** Max failed code attempts per token hash before the 429 lockout. */
    public const MAX_ATTEMPTS = 5;

    /** Lockout window in seconds; each failure re-arms it (login-OTP parity). */
    private const DECAY_SECONDS = 900;

    private const KEY_PREFIX = 'guest-cancel-otp:lockout:';

    public function __construct(private GuestOrderTokenService $tokens)
    {
    }

    /**
     * @throws GuestCheckoutConflictException 429 when the budget is exhausted
     */
    public function assertNotLocked(string $rawToken): void
    {
        if (RateLimiter::tooManyAttempts($this->keyFor($rawToken), self::MAX_ATTEMPTS)) {
            throw new GuestCheckoutConflictException(__('guest_checkout.rate_limited'), 429);
        }
    }

    /** Consume one failed code attempt against the token's hash. */
    public function recordFailure(string $rawToken): void
    {
        RateLimiter::hit($this->keyFor($rawToken), self::DECAY_SECONDS);
    }

    /** Clear the budget after a successful verification (D8). */
    public function clear(string $rawToken): void
    {
        RateLimiter::clear($this->keyFor($rawToken));
    }

    /**
     * The cache key: the token's keyed hash, never the raw credential.
     */
    public function keyFor(string $rawToken): string
    {
        return self::KEY_PREFIX . $this->tokens->hash($rawToken);
    }
}

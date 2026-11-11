<?php

namespace App\Services\Guest;

use App\Models\GuestIdentity;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Server-managed guest credential.
 *
 * Security contract:
 *  - 256-bit cryptographically random token, URL-safe encoded.
 *  - Only a keyed SHA-256 hash is persisted (guest_identities.token_hash).
 *  - The raw token travels exclusively in an HttpOnly cookie; it is never
 *    returned in JSON, never logged, and never trusted from the client as an
 *    identifier.
 *  - A separate readable CSRF cookie implements double-submit protection for
 *    cookie-authenticated state changes.
 */
class GuestIdentityService
{
    public const COOKIE = 'meem_guest';
    public const CSRF_COOKIE = 'meem_guest_csrf';

    /** Request-attribute keys (never serialized). */
    private const ATTR_IDENTITY = '_guest_identity';
    private const ATTR_RAW = '_guest_raw_token';

    public function __construct(private CookieJar $cookies)
    {
    }

    public function ttlDays(): int
    {
        return max(1, (int) config('guest_checkout.cookie.ttl_days', 7));
    }

    public function hashToken(string $rawToken): string
    {
        return hash_hmac('sha256', $rawToken, (string) config('app.key'));
    }

    /**
     * Resolve the verified guest identity from the HttpOnly cookie.
     * Invalid, expired and revoked tokens resolve to NULL (safe no-op).
     */
    public function current(Request $request): ?GuestIdentity
    {
        if ($request->attributes->has(self::ATTR_IDENTITY)) {
            return $request->attributes->get(self::ATTR_IDENTITY);
        }

        $identity = null;
        $raw = $this->rawToken($request);

        if ($raw !== null) {
            $identity = GuestIdentity::query()
                ->where('token_hash', $this->hashToken($raw))
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->first();
        }

        $request->attributes->set(self::ATTR_IDENTITY, $identity);

        return $identity;
    }

    /**
     * Create a brand-new guest identity and queue its cookies.
     * Returns [identity, rawToken]. The raw token is for cookie transport only.
     *
     * @return array{0: GuestIdentity, 1: string}
     */
    public function create(Request $request): array
    {
        $raw = $this->generateToken();
        $identity = GuestIdentity::create([
            'token_hash' => $this->hashToken($raw),
            'expires_at' => now()->addDays($this->ttlDays()),
        ]);

        $this->remember($request, $identity, $raw);
        $this->queueCookies($request, $identity, $raw);

        return [$identity, $raw];
    }

    /**
     * Extend retention after a GENUINE cart mutation only (reads never call
     * this). Re-queues the same raw cookie with the new expiry.
     */
    public function extend(Request $request, GuestIdentity $identity): void
    {
        $identity->forceFill(['expires_at' => now()->addDays($this->ttlDays())])->save();

        $raw = $this->rawToken($request);

        if ($raw !== null) {
            $this->queueCookies($request, $identity, $raw);
        }
    }

    public function clearCookies(): void
    {
        $this->cookies->queue($this->cookies->forget(self::COOKIE, $this->cookiePath(), $this->cookieDomain()));
        $this->cookies->queue($this->cookies->forget(self::CSRF_COOKIE, $this->cookiePath(), $this->cookieDomain()));
    }

    /**
     * Re-queue both cookies for an existing identity WITHOUT extending
     * retention. Used by the session endpoint so a browser that lost the
     * readable CSRF cookie can recover without a new identity/cart.
     */
    public function refreshCookies(Request $request, GuestIdentity $identity): void
    {
        $raw = $this->rawToken($request);

        if ($raw !== null) {
            $this->queueCookies($request, $identity, $raw);
        }
    }

    /**
     * Non-sensitive cookie metadata for the Frontend integration contract.
     * Never includes the token.
     */
    public function cookieMetadata(Request $request): array
    {
        return [
            'session_cookie' => self::COOKIE,
            'csrf_cookie' => self::CSRF_COOKIE,
            'csrf_header' => 'X-CSRF-Token',
            'path' => $this->cookiePath(),
            'domain' => $this->cookieDomain(),
            'same_site' => (string) config('guest_checkout.cookie.same_site', 'lax'),
            'secure' => $this->isSecure($request),
            'http_only' => true,
            'ttl_days' => $this->ttlDays(),
        ];
    }

    /** Deterministic, unguessable double-submit CSRF token for this identity. */
    public function csrfToken(GuestIdentity $identity): string
    {
        return hash_hmac('sha256', 'guest-csrf|' . $identity->id, (string) config('app.key'));
    }

    /** Request-scoped raw token (cookie transport only). */
    public function rawToken(Request $request): ?string
    {
        $raw = $request->attributes->get(self::ATTR_RAW);

        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        $cookie = $request->cookie(self::COOKIE);

        if (!is_string($cookie) || $cookie === '' || strlen($cookie) > 191) {
            return null;
        }

        return $cookie;
    }

    private function remember(Request $request, GuestIdentity $identity, string $raw): void
    {
        $request->attributes->set(self::ATTR_IDENTITY, $identity);
        $request->attributes->set(self::ATTR_RAW, $raw);
    }

    private function queueCookies(Request $request, GuestIdentity $identity, string $raw): void
    {
        $minutes = max(1, (int) now()->diffInMinutes($identity->expires_at));
        $secure = $this->isSecure($request);
        $sameSite = (string) config('guest_checkout.cookie.same_site', 'lax');

        if ($sameSite === 'none' && !$secure) {
            // SameSite=None requires Secure; refuse to emit an invalid cookie.
            $sameSite = 'lax';
        }

        $this->cookies->queue($this->cookies->make(
            self::COOKIE,
            $raw,
            $minutes,
            $this->cookiePath(),
            $this->cookieDomain(),
            $secure,
            true, // HttpOnly — never exposed to JavaScript
            false,
            $sameSite
        ));

        $this->cookies->queue($this->cookies->make(
            self::CSRF_COOKIE,
            $this->csrfToken($identity),
            $minutes,
            $this->cookiePath(),
            $this->cookieDomain(),
            $secure,
            false, // readable: required for double-submit
            false,
            $sameSite
        ));
    }

    private function isSecure(Request $request): bool
    {
        $configured = config('guest_checkout.cookie.secure');

        if ($configured !== null) {
            return (bool) $configured;
        }

        return $request->isSecure();
    }

    private function cookiePath(): string
    {
        return (string) config('guest_checkout.cookie.path', '/api');
    }

    private function cookieDomain(): ?string
    {
        $domain = config('guest_checkout.cookie.domain');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}

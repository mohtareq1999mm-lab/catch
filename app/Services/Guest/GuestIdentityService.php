<?php

namespace App\Services\Guest;

use App\Enums\GuestCredentialDomain;
use App\Enums\GuestErrorCode;
use App\Exceptions\GuestSessionRejection;
use App\Models\GuestIdentity;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Server-managed guest credential.
 *
 * Security contract:
 *  - 256-bit cryptographically random token, URL-safe encoded.
 *  - Only a keyed SHA-256 hash is persisted (guest_identities.token_hash and
 *    guest_identities.mobile_token_hash); the raw value never reaches storage.
 *  - Two transports resolve to the SAME GuestIdentity row:
 *      * Browser: raw token in an HttpOnly cookie (token_hash column).
 *      * Mobile:  raw token as Authorization: Bearer (mobile_token_hash column,
 *        domain-separated HMAC so a mobile token can never be replayed as a
 *        cookie credential and vice versa — ADR-0001).
 *  - The raw token travels exclusively in the HttpOnly cookie or the
 *    Authorization header; it is never returned in JSON after issuance, never
 *    logged, and never trusted from the client as an identifier.
 *  - A separate readable CSRF cookie implements double-submit protection for
 *    cookie-authenticated state changes. Bearer-authenticated requests are
 *    exempt (no ambient cookie credential to ride).
 *  - Presenting a cookie credential and a Bearer credential that resolve to
 *    DIFFERENT identities is rejected (no silent credential picking). The
 *    exception message is the RAW translation key so app/Exceptions/Handler.php
 *    can expose the machine-readable `code` key (A10) without touching any
 *    existing response shape.
 *  - A Sanctum customer token never matches the guest Bearer shape gate, so
 *    customer authentication and Login Merge are unaffected.
 */
class GuestIdentityService
{
    public const COOKIE = 'meem_guest';
    public const CSRF_COOKIE = 'meem_guest_csrf';

    /** Auth modes recorded on the request (never serialized). */
    public const MODE_COOKIE = 'cookie';
    public const MODE_BEARER = 'bearer';

    /** Upper bound for a raw credential before any comparison (legacy column width). */
    private const TOKEN_MAX_LENGTH = 191;

    public static function cookieName(): string
    {
        return (string) config('guest_checkout.cookie.name', self::COOKIE);
    }

    public static function csrfCookieName(): string
    {
        return (string) config('guest_checkout.cookie.csrf_name', self::CSRF_COOKIE);
    }

    /** Request-attribute keys (never serialized). */
    private const ATTR_IDENTITY = '_guest_identity';
    private const ATTR_RAW = '_guest_raw_token';
    private const ATTR_MODE = '_guest_auth_mode';

    public function __construct(
        private CookieJar $cookies,
        private GuestCredentialService $credentials,
    ) {
    }

    public function ttlDays(): int
    {
        return max(1, (int) config('guest_checkout.cookie.ttl_days', 7));
    }

    public function hashToken(string $rawToken): string
    {
        return $this->credentials->hash($rawToken, GuestCredentialDomain::Legacy);
    }

    /**
     * Domain-separated hash for the mobile Bearer transport (ADR-0001): the
     * prefix keeps a mobile token from validating as a browser cookie
     * credential and vice versa, even if the raw value leaks between
     * transports.
     */
    public function hashMobileToken(string $rawToken): string
    {
        return $this->credentials->hash($rawToken, GuestCredentialDomain::Mobile);
    }

    /**
     * Resolve the verified guest identity from either transport:
     * the HttpOnly cookie, or the Authorization: Bearer header.
     *
     * Invalid, expired and revoked tokens resolve to NULL (safe no-op).
     * Cookie + Bearer resolving to different identities is a hard 401
     * `credential_conflict` (never silent picking).
     */
    public function current(Request $request): ?GuestIdentity
    {
        if ($request->attributes->has(self::ATTR_IDENTITY)) {
            return $request->attributes->get(self::ATTR_IDENTITY);
        }

        $cookieIdentity = $this->fromCookie($request);
        $bearerIdentity = $this->fromBearer($request);

        if ($cookieIdentity && $bearerIdentity && $cookieIdentity->id !== $bearerIdentity->id) {
            throw new GuestSessionRejection(
                GuestErrorCode::CREDENTIAL_CONFLICT,
                401,
                'guest_checkout.credential_conflict'
            );
        }

        $identity = $cookieIdentity ?? $bearerIdentity;

        if ($identity !== null) {
            // The explicit Bearer credential wins when both transports resolve
            // the same identity: rotate/revoke then work from a cookie-carrying
            // client, and the strictest credential determines the mode.
            $request->attributes->set(
                self::ATTR_MODE,
                $bearerIdentity !== null ? self::MODE_BEARER : self::MODE_COOKIE
            );
        } else {
            $request->attributes->set(self::ATTR_MODE, null);
        }

        $request->attributes->set(self::ATTR_IDENTITY, $identity);

        return $identity;
    }

    /** 'cookie' | 'bearer' | null for this request (resolves lazily if needed). */
    public function authMode(Request $request): ?string
    {
        if (!$request->attributes->has(self::ATTR_MODE)) {
            $this->current($request);
        }

        return $request->attributes->get(self::ATTR_MODE);
    }

    /**
     * Create a brand-new guest identity and queue its cookies.
     * Returns [identity, rawToken]. The raw token is for cookie transport only.
     *
     * @return array{0: GuestIdentity, 1: string}
     */
    public function create(Request $request): array
    {
        $raw = $this->credentials->mint();
        $identity = GuestIdentity::create([
            'token_hash' => $this->hashToken($raw),
            'expires_at' => now()->addDays($this->ttlDays()),
        ]);

        $this->remember($request, $identity, $raw);
        $this->queueCookies($request, $identity, $raw);

        return [$identity, $raw];
    }

    /**
     * Create a brand-new guest identity for a native mobile client (D1/D9).
     * No cookies are queued: the Mobile Credential travels as
     * Authorization: Bearer. Returns [identity, rawToken]; the raw token is
     * returned exactly once.
     *
     * The legacy token_hash column must stay non-null + unique, so mobile
     * identities carry an unused random browser-slot hash that no cookie can
     * ever match.
     *
     * @return array{0: GuestIdentity, 1: string}
     */
    public function createForMobile(Request $request): array
    {
        $raw = $this->credentials->mint();
        $identity = GuestIdentity::create([
            'token_hash' => $this->hashToken($this->credentials->mint()),
            'mobile_token_hash' => $this->hashMobileToken($raw),
            'expires_at' => now()->addDays($this->ttlDays()),
        ]);

        $request->attributes->set(self::ATTR_IDENTITY, $identity);
        $request->attributes->set(self::ATTR_MODE, self::MODE_BEARER);

        return [$identity, $raw];
    }

    /**
     * Explicit mobile token Rotation (D6/A3): issue a fresh Bearer token for
     * the SAME identity and invalidate the previous one immediately. Callers
     * must present the identity via the Bearer transport.
     */
    public function rotateMobileToken(Request $request, GuestIdentity $identity): string
    {
        $raw = $this->credentials->mint();

        $identity->forceFill([
            'mobile_token_hash' => $this->hashMobileToken($raw),
            'expires_at' => now()->addDays($this->ttlDays()),
        ])->save();

        $request->attributes->set(self::ATTR_MODE, self::MODE_BEARER);
        $request->attributes->set(self::ATTR_IDENTITY, $identity);

        return $raw;
    }

    /**
     * Revocation of the identity behind a mobile session: clears the Bearer
     * credential and ends the identity (revoked_at). Revocation is
     * identity-level: a cookie credential for the same identity (if one
     * existed) dies with it.
     */
    public function revokeMobile(GuestIdentity $identity): void
    {
        $identity->forceFill($this->credentials->mobileRevocation())->save();
    }

    /**
     * Extend retention after a GENUINE cart mutation only (reads never call
     * this). Only the identity row expiry moves — the cookie transport is
     * re-queued separately at the HTTP seam, which is exactly the shared
     * Retention policy (A1).
     */
    public function extendRetention(GuestIdentity $identity): void
    {
        $identity->forceFill(['expires_at' => now()->addDays($this->ttlDays())])->save();
    }

    public function clearCookies(): void
    {
        $this->cookies->queue($this->cookies->forget(self::cookieName(), $this->cookiePath(), $this->cookieDomain()));
        $this->cookies->queue($this->cookies->forget(self::csrfCookieName(), $this->cookiePath(), $this->cookieDomain()));
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
            'session_cookie' => self::cookieName(),
            'csrf_cookie' => self::csrfCookieName(),
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
        return $this->credentials->hash((string) $identity->id, GuestCredentialDomain::Csrf);
    }

    /** Format gate for Bearer tokens; rejects Sanctum customer tokens ("id|…"). */
    public function isValidTokenFormat(?string $token): bool
    {
        return is_string($token) && $token !== '' && strlen($token) <= self::TOKEN_MAX_LENGTH
            && $this->credentials->isValidFormat($token);
    }

    /** Request-scoped raw token (cookie transport only). */
    public function rawToken(Request $request): ?string
    {
        $raw = $request->attributes->get(self::ATTR_RAW);

        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        $cookie = $request->cookie(self::cookieName());

        if (!is_string($cookie) || $cookie === '' || strlen($cookie) > self::TOKEN_MAX_LENGTH) {
            return null;
        }

        return $cookie;
    }

    /** Resolve the identity from the HttpOnly cookie (existing browser path). */
    private function fromCookie(Request $request): ?GuestIdentity
    {
        $raw = $request->cookie(self::cookieName());

        if (!is_string($raw) || $raw === '' || strlen($raw) > self::TOKEN_MAX_LENGTH) {
            return null;
        }

        $identity = GuestIdentity::query()
            ->where('token_hash', $this->hashToken($raw))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($identity !== null) {
            $request->attributes->set(self::ATTR_RAW, $raw);
        }

        return $identity;
    }

    /**
     * Resolve the identity from Authorization: Bearer. Only tokens matching
     * the guest pattern AND a stored mobile_token_hash count; anything else
     * (customer Sanctum tokens, garbage, foreign credentials) is ignored so
     * the request falls through to the cookie path.
     */
    private function fromBearer(Request $request): ?GuestIdentity
    {
        $token = $request->bearerToken();

        if (!$this->isValidTokenFormat($token)) {
            return null;
        }

        return GuestIdentity::query()
            ->where('mobile_token_hash', $this->hashMobileToken($token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
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
            self::cookieName(),
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
            self::csrfCookieName(),
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
}

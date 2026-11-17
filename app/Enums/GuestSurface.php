<?php

namespace App\Enums;

/**
 * One surface token per guest route (round-2 ticket #52).
 *
 * A GuestSurface case declares the limiter the route already names plus its
 * identity, CSRF and cookie-refresh duties. routes/api.php attaches exactly
 * ONE case per guest route, and the guest Origin guard is declared once on
 * the `guest` prefix group — never repeated per route.
 *
 * The route contract test (GuestRouteContractTest) derives the expected
 * middleware of every guest route from this enum, so adding a guest route
 * becomes one coordinated edit (a route + a surface case) instead of a
 * hand-written middleware matrix.
 *
 * CSRF semantics: the `guest.csrf` middleware derives its decision from the
 * credential transport at RUNTIME — it enforces only cookie-transport state
 * changes and stays free for reads, requests with no ambient identity,
 * Bearer-authenticated requests, and public one-way token paths (the
 * approved route-security decisions). A case with enforcesCsrf() === false
 * means "the guard must not be MOUNTED here at all" (public-token
 * / Bearer-only surfaces); it is not a weaker runtime check.
 */
enum GuestSurface: string
{
    /** Public cold-start: creates the Guest Identity (cookie transport). */
    case SESSION_OPEN = 'session_open';

    /** Cookie-credential revocation: idempotent with no identity. */
    case SESSION_REVOKE = 'session_revoke';

    /** Mobile native Bearer transport: opaque token is the credential (D1). */
    case MOBILE_SESSION = 'mobile_session';

    /** Cold-start cart probe (D9): resolves lazily, no identity required. */
    case CART_READ = 'cart_read';

    /** Order read bound to an existing guest session. */
    case ORDER_READ = 'order_read';

    /** Cart mutations: identity-bound session + cookie-transport CSRF + refresh. */
    case CART_WRITE = 'cart_write';

    /** Public one-way token path: order tracking. */
    case ORDER_TRACK = 'order_track';

    /** Public one-way token path: order cancellation. */
    case ORDER_CANCEL = 'order_cancel';

    /** Payment-bound order state changes. */
    case ORDER_PAYMENT = 'order_payment';

    /** Session-bound tracking-link re-delivery to the owning guest (#80). */
    case ORDER_RESEND = 'order_resend';

    /** Terminal checkout moment. */
    case CHECKOUT = 'checkout';

    public function limiter(): string
    {
        return match ($this) {
            self::SESSION_OPEN, self::SESSION_REVOKE, self::MOBILE_SESSION => 'guest_session',
            self::CART_READ, self::ORDER_READ => 'guest_cart_read',
            self::CART_WRITE => 'guest_cart_write',
            self::ORDER_TRACK => 'guest_tracking',
            self::ORDER_CANCEL => 'guest_cancel',
            self::ORDER_PAYMENT => 'guest_payment',
            self::ORDER_RESEND => 'guest_resend',
            self::CHECKOUT => 'guest_checkout',
        };
    }

    public function requiresIdentity(): bool
    {
        return match ($this) {
            self::SESSION_OPEN,
            self::SESSION_REVOKE,
            self::MOBILE_SESSION,
            self::CART_READ,
            self::ORDER_TRACK,
            self::ORDER_CANCEL => false,
            default => true,
        };
    }

    public function enforcesCsrf(): bool
    {
        return match ($this) {
            self::SESSION_REVOKE,
            self::CART_WRITE,
            self::ORDER_PAYMENT,
            self::ORDER_RESEND,
            self::CHECKOUT => true,
            default => false,
        };
    }

    /**
     * Only cart mutations re-queue the browser cookie transport; retention is
     * owned by the domain, the cookie re-queue by the HTTP seam (ticket #49).
     */
    public function refreshesCookie(): bool
    {
        return $this === self::CART_WRITE;
    }

    /**
     * The derived middleware stack. The guest Origin guard is NOT part of any
     * surface: it is declared once on the `guest` prefix group.
     */
    public function middleware(): array
    {
        return array_values(array_filter([
            $this->requiresIdentity() ? 'guest.session' : null,
            $this->enforcesCsrf() ? 'guest.csrf' : null,
            $this->refreshesCookie() ? 'guest.cookie_refresh' : null,
            'guest.throttle:' . $this->limiter(),
        ]));
    }
}

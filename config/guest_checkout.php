<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Guest credential cookie
    |--------------------------------------------------------------------------
    |
    | The raw guest token is delivered in an HttpOnly, server-managed cookie and
    | never appears in JSON or JavaScript. Name/path defaults are fixed so the
    | CSRF exclusion in EncryptCookies stays in sync.
    |
    | Frontend/Backend domains:
    |  - Same-site subdomains (shop.example.com -> api.example.com):
    |    keep domain NULL (host-only), same_site=lax, CORS_ALLOWED_ORIGINS set
    |    to the exact frontend origin, CORS_SUPPORTS_CREDENTIALS=true.
    |  - Different registrable domains: third-party cookies are blocked by
    |    browsers. Use a same-site BFF/reverse proxy (recommended) OR set
    |    same_site=none + secure=true and accept the reliability risk. Never
    |    fall back to localStorage.
    |
    */
    'cookie' => [
        'name' => env('GUEST_COOKIE_NAME', 'meem_guest'),
        'csrf_name' => env('GUEST_CSRF_COOKIE_NAME', 'meem_guest_csrf'),
        'path' => env('GUEST_COOKIE_PATH', '/api'),
        'domain' => env('GUEST_COOKIE_DOMAIN') ?: null,
        // NULL => auto (Secure whenever the request is HTTPS).
        'secure' => env('GUEST_COOKIE_SECURE') === null
            ? null
            : filter_var(env('GUEST_COOKIE_SECURE'), FILTER_VALIDATE_BOOLEAN),
        'same_site' => env('GUEST_COOKIE_SAMESITE', 'lax'),
        // Guest cart/credential inactivity window (approved: 7 days).
        'ttl_days' => (int) env('GUEST_CART_TTL_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Order credentials
    |--------------------------------------------------------------------------
    */

    // Public tracking stays valid for the whole order lifecycle and expires
    // this many days after the terminal status (delivered/cancelled).
    'tracking_grace_days' => (int) env('GUEST_TRACKING_GRACE_DAYS', 30),

    /*
    | Public tracking link sent to guests (SMS/email) after checkout.
    | The link targets the FRONTEND (decoupled domain); the frontend reads the
    | token from the query string and POSTs it to /api/v1/guest/orders/track.
    | Only the tracking token is ever sent - the cancellation token is never
    | placed in a shareable link.
    |
    | Cross-domain/CORS (same constraints as the guest cookie): for same-site
    | subdomains keep the cookie domain empty and allow exactly the frontend
    | origin (wildcards are rejected when credentials are sent); for different
    | registrable domains a same-site BFF/reverse proxy is required. In
    | production base_url must be a valid https:// URL - delivery is skipped
    | with a token-free warning otherwise.
    */
    'tracking_link' => [
        'enabled' => filter_var(env('GUEST_TRACKING_LINK_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'base_url' => env('GUEST_FRONTEND_URL') ?: env('APP_URL_FRONTEND', 'http://localhost'),
        'path' => env('GUEST_TRACKING_LINK_PATH', '/track-order'),
    ],

    // Single-use cancellation link lifetime.
    'cancellation_link_ttl_hours' => (int) env('GUEST_CANCEL_TTL_HOURS', 72),

    // Guest checkout supports physical products only (locked decision).
    // Kept non-env-configurable on purpose: enabling it requires a product
    // decision about delivery/entitlements, not a deployment toggle.
    'digital_products_allowed' => false,

    // Cart-cleanup batch size (bounded cleanup).
    'prune_batch_size' => (int) env('GUEST_CART_PRUNE_BATCH', 200),

    /*
    |--------------------------------------------------------------------------
    | Guest order claiming (OTP-gated; DISABLED)
    |--------------------------------------------------------------------------
    |
    | Two independent gates must both be satisfied before any claim can occur:
    |  1. this flag is true, AND
    |  2. App\Contracts\GuestPhoneVerificationContract is bound to a REAL
    |     verifier (the default NullGuestPhoneVerification always returns null).
    |
    | Do not flip this flag or bind a verifier until OTP verification is
    | production-ready and the invocation points in UserController are
    | intentionally enabled. See docs/guest-order-claim-activation.md.
    |
    */
    'order_claim' => [
        'enabled' => filter_var(env('GUEST_ORDER_CLAIM_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'token_ttl_hours' => (int) env('GUEST_ORDER_CLAIM_TTL_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting (7 public guest surfaces)
    |--------------------------------------------------------------------------
    |
    | Values are per-minute attempts. Key = resolved guest identity when the
    | credential is present, otherwise the client IP. Protected surfaces fail
    | closed if the limiter backend is unavailable.
    |
    */
    'throttle' => [
        'guest_session' => [
            'max' => (int) env('GUEST_THROTTLE_SESSION', 10),
            'decay' => 1,
        ],
        'guest_cart_read' => [
            'max' => (int) env('GUEST_THROTTLE_CART_READ', 120),
            'decay' => 1,
        ],
        'guest_cart_write' => [
            'max' => (int) env('GUEST_THROTTLE_CART_WRITE', 40),
            'decay' => 1,
        ],
        'guest_checkout' => [
            'max' => (int) env('GUEST_THROTTLE_CHECKOUT', 6),
            'decay' => 1,
        ],
        'guest_payment' => [
            'max' => (int) env('GUEST_THROTTLE_PAYMENT', 6),
            'decay' => 1,
        ],
        'guest_tracking' => [
            'max' => (int) env('GUEST_THROTTLE_TRACKING', 20),
            'decay' => 1,
        ],
        'guest_cancel_otp' => [
            'max' => (int) env('GUEST_THROTTLE_CANCEL_OTP', 5),
            'decay' => 1,
        ],
        'guest_cancel' => [
            'max' => (int) env('GUEST_THROTTLE_CANCEL', 5),
            'decay' => 1,
        ],
    ],
];

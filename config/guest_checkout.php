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
        // Cookie names are FIXED constants in GuestIdentityService because
        // EncryptCookies' exclusion list must stay byte-identical.
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
        'guest_cancel' => [
            'max' => (int) env('GUEST_THROTTLE_CANCEL', 5),
            'decay' => 1,
        ],
    ],
];

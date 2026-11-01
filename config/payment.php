<?php

return [
    'default_gateway' => env('DEFAULT_PAYMENT_GATEWAY', 'myfatoorah'),
    'default_currency' => env('DEFAULT_CURRENCY', 'KWD'),
    'order_timeout_hours' => env('ORDER_TIMEOUT_HOURS', 24),
    'cod_order_timeout_hours' => env('COD_ORDER_TIMEOUT_HOURS', 24 * 7),

    // B5 hardening: amount/currency mismatch bypass on the MyFatoorah test host
    // requires BOTH this explicit flag AND a local/testing environment (checked in
    // OrderController::isTestGatewayBypassAllowed). Default OFF — a staging host
    // pointing at apitest must never silently complete underpaid orders.
    'test_gateway_bypass_enabled' => env('PAYMENT_TEST_GATEWAY_BYPASS', false),

    'gateways' => [
        'myfatoorah' => [
            'class' => App\Services\Gateway\MyFatoorahGateway::class,
            'enabled' => env('MYFATOORAH_ENABLED', true),
            'api_key' => env('MYFATOORAH_API_KEY'),
            'base_url' => env('MYFATOORAH_BASE_URL', 'https://apitest.myfatoorah.com/v2/'),
            // Currencies supported by MyFatoorah. Checkout is blocked when the
            // order currency is not in this list (no silent base-currency fallback).
            'supported_currencies' => array_values(array_filter(array_map(
                'strtoupper',
                explode(',', (string) env('MYFATOORAH_SUPPORTED_CURRENCIES', 'KWD,SAR,AED,BHD,QAR,OMR,EGP'))
            ))),
            'methods' => ['online'],
        ],
        'stripe' => [
            'class' => App\Services\Gateway\StripeGateway::class,
            'enabled' => env('STRIPE_ENABLED', false),
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'supported_currencies' => array_values(array_filter(array_map(
                'strtoupper',
                explode(',', (string) env('STRIPE_SUPPORTED_CURRENCIES', 'USD,EUR,KWD,SAR,AED'))
            ))),
            'methods' => ['online'],
        ],
        'paypal' => [
            'class' => 'App\Services\Gateway\PayPalGateway',
            'enabled' => env('PAYPAL_ENABLED', false),
            'mode' => env('PAYPAL_MODE', 'sandbox'),
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            'supported_currencies' => array_values(array_filter(array_map(
                'strtoupper',
                explode(',', (string) env('PAYPAL_SUPPORTED_CURRENCIES', 'USD,EUR'))
            ))),
            'methods' => ['online'],
        ],
        // Tap Payments (https://developers.tap.company). Disabled by default;
        // enabling it does not affect other gateways. The charge currency is
        // NEVER hardcoded here — TapGateway derives it from the order via
        // PaymentCurrencyResolver and validates against supported_currencies.
        // Default list = Tap's documented platform currencies (the merchant
        // account may enable a subset; unsupported currencies fail closed).
        // Webhook hashstring verification uses the SAME secret_key (Tap's
        // documented mechanism) — no separate webhook secret key exists.
        'tap' => [
            'class' => 'App\Services\Gateway\TapGateway',
            'enabled' => env('TAP_ENABLED', false),
            'secret_key' => env('TAP_SECRET_KEY'),
            'base_url' => env('TAP_BASE_URL', 'https://api.tap.company/v2'),
            'source_id' => env('TAP_SOURCE_ID', 'src_all'),
            'supported_currencies' => array_values(array_filter(array_map(
                'strtoupper',
                explode(',', (string) env('TAP_SUPPORTED_CURRENCIES', 'AED,BHD,EGP,EUR,GBP,JOD,KWD,OMR,QAR,SAR,USD'))
            ))),
            'methods' => ['online'],
        ],
        // Tabby (https://docs.tabby.ai) — MENA Buy-Now-Pay-Later. Disabled by
        // default; enabling it does not affect other gateways. The payment
        // currency is NEVER hardcoded here — TabbyGateway derives it from the
        // order via PaymentCurrencyResolver and validates against
        // supported_currencies (Tabby's documented currency enum: AED, SAR).
        // Lifecycle (capture-first, docs.tabby.ai payment-processing):
        // retrieval is always server-side; an AUTHORIZED payment is captured
        // with a constant idempotent reference_id ("capture-{payment.id}") and
        // an order is completed ONLY after Tabby confirms the capture
        // (retrieved status CLOSED + non-empty captures[]). Unresolved
        // captures leave the transaction row pending (resumable) — never paid.
        // Webhooks are self-service registered (POST /api/v1/webhooks with
        // X-Merchant-Code) and carry a custom header Tabby echoes on every
        // delivery; TABBY_WEBHOOK_HEADER is the registered header NAME and
        // TABBY_WEBHOOK_SECRET its VALUE (constant-time compared, fail-closed).
        'tabby' => [
            'class' => 'App\Services\Gateway\TabbyGateway',
            'enabled' => env('TABBY_ENABLED', false),
            'secret_key' => env('TABBY_SECRET_KEY'),
            // Regional base: api.tabby.ai (UAE) or api.tabby.sa (KSA) — DOCS:
            // docs.tabby.ai/api-reference/overview.
            'base_url' => env('TABBY_BASE_URL', 'https://api.tabby.ai/api/v2'),
            'merchant_code' => env('TABBY_MERCHANT_CODE'),
            'webhook_header' => env('TABBY_WEBHOOK_HEADER', 'x-tabby-signature'),
            'webhook_secret' => env('TABBY_WEBHOOK_SECRET'),
            'supported_currencies' => array_values(array_filter(array_map(
                'strtoupper',
                explode(',', (string) env('TABBY_SUPPORTED_CURRENCIES', 'AED,SAR'))
            ))),
            // Capture attempt budget: POST /payments/{id}/captures retries add
            // attempts on 409 "payment is locked" / 5xx / transport failure with
            // the SAME reference_id (idempotent). Bounded so a buyer callback
            // never hangs; an unresolved capture stays pending (never-terminal).
            'capture_attempts' => (int) env('TABBY_CAPTURE_ATTEMPTS', 2),
            'capture_retry_delay_ms' => (int) env('TABBY_CAPTURE_RETRY_DELAY_MS', 2000),
            'methods' => ['online'],
        ],
        // Tamara (https://docs.tamara.co) — MENA Buy-Now-Pay-Later, direct
        // integration. Disabled by default; enabling it does not affect
        // other gateways. The payment currency is NEVER hardcoded here —
        // TamaraGateway derives it from the order via PaymentCurrencyResolver
        // and validates against supported_currencies (Tamara's documented
        // currency enum: SAR, AED, BHD, KWD, OMR; the merchant ACCOUNT may
        // enable a subset — narrow/env this list as required).
        // Lifecycle (docs.tamara.co approved→authorised→captured): the money
        // obligation is AUTHORISED (docs: "once the authorised stage is
        // reached, you can consider the order as paid") — local completion
        // happens exactly once at authorised via the canonical completion
        // service; capture is a later settlement operation tied to shipment
        // (POST /payments/capture) recorded as a ledger, never a second
        // completion. Un-captured authorised orders are auto-captured by
        // Tamara after 21 days.
        // Webhooks are registered one-time in the Tamara partner portal;
        // notifications carry a `tamaraToken` (query param AND Authorization
        // Bearer) which is an HS256 JWT signed with the Notification Token —
        // TAMARA_NOTIFICATION_TOKEN verifies it; the API Token performs API
        // calls. Secrets are env-only (never admin-settable overrides).
        'tamara' => [
            'class' => 'App\\Services\\Gateway\\TamaraGateway',
            'enabled' => env('TAMARA_ENABLED', false),
            // sandbox => https://api-sandbox.tamara.co / production =>
            // https://api.tamara.co; DOCS: servers enum getorderdetails.
            'environment' => env('TAMARA_ENVIRONMENT', 'sandbox'),
            // Optional explicit override; "" (default) keeps the
            // environment-derived URL above. Never append a trailing slash.
            'api_url' => env('TAMARA_API_URL'),
            'api_token' => env('TAMARA_API_TOKEN'),
            'notification_token' => env('TAMARA_NOTIFICATION_TOKEN'),
            'public_key' => env('TAMARA_PUBLIC_KEY'),
            'supported_currencies' => array_values(array_filter(array_map(
                'strtoupper',
                explode(',', (string) env('TAMARA_SUPPORTED_CURRENCIES', 'SAR,AED,BHD,KWD,OMR'))
            ))),
            // Tamara-DOCUMENTED eligibility budget: 200ms, fail-open (show
            // Tamara on timeout/error).
            'eligibility_timeout_ms' => (int) env('TAMARA_ELIGIBILITY_TIMEOUT_MS', 200),
            // Bounded re-arbitration budget for ambiguous capture outcomes
            // (fresh reads, never blind retries of the POST itself).
            'capture_attempts' => (int) env('TAMARA_CAPTURE_ATTEMPTS', 2),
            // Checkout plan knobs (Tamara examples carry PAY_BY_INSTALMENTS
            // / 3). UNVERIFIED enum casing against a live sandbox — override
            // via env without a code change if Tamara instructs otherwise.
            'payment_type' => env('TAMARA_PAYMENT_TYPE', 'PAY_BY_INSTALMENTS'),
            'instalments' => (int) env('TAMARA_INSTALMENTS', 3),
            'platform_label' => env('TAMARA_PLATFORM_LABEL', 'Meem'),
            'methods' => ['online'],
        ],
    ],
];

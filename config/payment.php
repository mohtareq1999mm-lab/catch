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
    ],
];

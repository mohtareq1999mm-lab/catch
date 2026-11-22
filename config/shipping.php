<?php

declare(strict_types=1);

/*
 * Deployment-backed carrier configuration.
 *
 * Secrets exist only as env references; there is no DB-backed credential
 * authority and no admin-editable provider record. Both mode blocks are
 * disabled by default. The factory fails closed when a mode is mixed,
 * disabled, missing credentials, or missing capability verification.
 *
 * #143 webhook delivery is an inbox/verification surface only. It never
 * mutates shipment, order, fulfillment, payment, or inventory state.
 * Webhooks are therefore a WAKE-UP signal, not the source of truth: polling
 * the existing read-only tracking surface is the documented fallback whenever
 * a provider callback is unavailable, dropped, or parked for replay. The
 * durable inbox plus stable event identity make webhook replay safe.
 */
return [
    'default_mode' => env('SHIPPING_DEFAULT_MODE', 'sandbox'),

    // #143: fail-closed limiter for provider -> trusted source IP. Backend
    // failure returns 503 (no verification/DB work); a real limit returns 429.
    'webhook_rate_limit' => [
        'max_attempts' => max(1, (int) env('SHIPPING_WEBHOOK_RATE_LIMIT_MAX', 60)),
        'decay_seconds' => max(1, (int) env('SHIPPING_WEBHOOK_RATE_LIMIT_DECAY_SECONDS', 60)),
    ],

    'providers' => [
        'null' => [
            // Local placeholder only: no outbound calls, no capabilities.
            'adapter' => App\Services\Shipment\Providers\NullCarrierGateway::class,
            'mode' => env('SHIPPING_NULL_MODE', 'sandbox'),
            // Non-secret identity, stable across credential rotation.
            'account_code' => env('SHIPPING_NULL_ACCOUNT_CODE', 'null-local'),
            'channels' => ['web', 'mobile'],
            'geographies' => ['EG'],
            'modes' => [
                'sandbox' => [
                    'enabled' => env('SHIPPING_NULL_SANDBOX_ENABLED', false),
                    'base_url' => env('SHIPPING_NULL_SANDBOX_BASE_URL'),
                    'credentials' => [
                        'api_key' => env('SHIPPING_NULL_SANDBOX_API_KEY'),
                        'api_secret' => env('SHIPPING_NULL_SANDBOX_API_SECRET'),
                    ],
                    'timeout_seconds' => (int) env('SHIPPING_NULL_SANDBOX_TIMEOUT_SECONDS', 5),
                    'retry' => [
                        'attempts' => (int) env('SHIPPING_NULL_SANDBOX_RETRY_ATTEMPTS', 2),
                        'backoff_ms' => (int) env('SHIPPING_NULL_SANDBOX_RETRY_BACKOFF_MS', 100),
                        'max_backoff_ms' => (int) env('SHIPPING_NULL_SANDBOX_RETRY_MAX_BACKOFF_MS', 1000),
                    ],
                    'queues' => [
                        'dispatch' => env('SHIPPING_NULL_SANDBOX_DISPATCH_QUEUE', 'shipping.sandbox.dispatch'),
                        'webhook' => env('SHIPPING_NULL_SANDBOX_WEBHOOK_QUEUE', 'shipping.sandbox.webhook'),
                    ],
                    'webhook' => [
                        'secret' => env('SHIPPING_NULL_SANDBOX_WEBHOOK_SECRET'),
                        'version' => env('SHIPPING_NULL_SANDBOX_WEBHOOK_VERSION', 'v1'),
                    ],
                    // No capability is verified until a recorded fixture or
                    // provider confirmation is attached at deployment time.
                    'capability_verification' => [],
                    'status_map' => [
                        'version' => 1,
                        'status_map_owner' => 'shipping-platform',
                        'statuses' => [],
                    ],
                ],
                'production' => [
                    'enabled' => env('SHIPPING_NULL_PRODUCTION_ENABLED', false),
                    'base_url' => env('SHIPPING_NULL_PRODUCTION_BASE_URL'),
                    'credentials' => [
                        'api_key' => env('SHIPPING_NULL_PRODUCTION_API_KEY'),
                        'api_secret' => env('SHIPPING_NULL_PRODUCTION_API_SECRET'),
                    ],
                    'timeout_seconds' => (int) env('SHIPPING_NULL_PRODUCTION_TIMEOUT_SECONDS', 5),
                    'retry' => [
                        'attempts' => (int) env('SHIPPING_NULL_PRODUCTION_RETRY_ATTEMPTS', 2),
                        'backoff_ms' => (int) env('SHIPPING_NULL_PRODUCTION_RETRY_BACKOFF_MS', 100),
                        'max_backoff_ms' => (int) env('SHIPPING_NULL_PRODUCTION_RETRY_MAX_BACKOFF_MS', 1000),
                    ],
                    'queues' => [
                        'dispatch' => env('SHIPPING_NULL_PRODUCTION_DISPATCH_QUEUE', 'shipping.production.dispatch'),
                        'webhook' => env('SHIPPING_NULL_PRODUCTION_WEBHOOK_QUEUE', 'shipping.production.webhook'),
                    ],
                    'webhook' => [
                        'secret' => env('SHIPPING_NULL_PRODUCTION_WEBHOOK_SECRET'),
                        'version' => env('SHIPPING_NULL_PRODUCTION_WEBHOOK_VERSION', 'v1'),
                    ],
                    'capability_verification' => [],
                    'status_map' => [
                        'version' => 1,
                        'status_map_owner' => 'shipping-platform',
                        'statuses' => [],
                    ],
                ],
            ],
        ],
    ],
];

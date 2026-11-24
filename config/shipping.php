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
$pollingTimeBudget = max(1, (int) env('SHIPPING_POLLING_TIME_BUDGET_SECONDS', 300));
$pollingCallTimeout = max(1, (int) env('SHIPPING_POLLING_CALL_TIMEOUT_SECONDS', 30));

return [
    'default_mode' => env('SHIPPING_DEFAULT_MODE', 'sandbox'),

    /*
     * #148: provider choice is a deployment decision. Callers never supply a
     * provider code for initial assignment; only an authorized, audited admin
     * override may select a different configured provider.
     */
    'default_provider' => env('SHIPPING_DEFAULT_PROVIDER', 'internal'),

    // #143/#153: fail-closed limiter for provider -> trusted source IP. Backend
    // failure returns 503 (no verification/DB work); a real limit returns 429.
    // Source IP is derived only through configured trusted proxies; missing or
    // empty configuration fails closed before verification/inbox work.
    'webhook_rate_limit' => [
        'max_attempts' => max(1, (int) env('SHIPPING_WEBHOOK_RATE_LIMIT_MAX', 60)),
        'decay_seconds' => max(1, (int) env('SHIPPING_WEBHOOK_RATE_LIMIT_DECAY_SECONDS', 60)),
        'trusted_proxies' => array_values(array_filter(array_map(
            static fn (string $proxy): string => trim($proxy),
            explode(',', (string) env('SHIPPING_WEBHOOK_TRUSTED_PROXIES', ''))
        ), static fn (string $proxy): bool => $proxy !== '')),
    ],

    // #159: versioned HMAC fallback fingerprints. Secrets are deployment-owned;
    // never persisted. Retained versions keep dedupe effective across rotation.
    'webhook_fingerprints' => [
        'current_version' => env('SHIPPING_WEBHOOK_FINGERPRINT_VERSION', 'v1'),
        'keys' => array_filter([
            'v1' => env('SHIPPING_WEBHOOK_FINGERPRINT_KEY_V1', env('APP_KEY')),
            'v2' => env('SHIPPING_WEBHOOK_FINGERPRINT_KEY_V2'),
            'v3' => env('SHIPPING_WEBHOOK_FINGERPRINT_KEY_V3'),
        ], static fn ($value): bool => is_string($value) && trim($value) !== ''),
    ],

    // #160: carrier labels are private artifacts. They are stored on a
    // private disk only, validated before acceptance, and exposed only via
    // authorized short-lived links. Never place labels on a public disk.
    'labels' => [
        'disk' => env('SHIPPING_LABEL_DISK', 'private'),
        'directory' => env('SHIPPING_LABEL_DIRECTORY', 'shipping-labels'),
        'max_bytes' => max(1, (int) env('SHIPPING_LABEL_MAX_BYTES', 5 * 1024 * 1024)),
        'allowed_formats' => ['pdf', 'png', 'zpl'],
        'signed_url_ttl_minutes' => max(1, (int) env('SHIPPING_LABEL_SIGNED_TTL_MINUTES', 10)),
    ],

    // #152 durable outbox: deterministic failures retry until this budget, then
    // terminal FAILED. UNKNOWN (ambiguous external outcome) is reconciled with
    // its own budget/age before escalating to FAILED_UNKNOWN for operator review.
    'outbox_max_attempts' => max(1, (int) env('SHIPPING_OUTBOX_MAX_ATTEMPTS', 25)),
    'outbox_unknown_max_attempts' => max(1, (int) env('SHIPPING_OUTBOX_UNKNOWN_MAX_ATTEMPTS', 10)),
    'outbox_unknown_max_age_seconds' => max(60, (int) env('SHIPPING_OUTBOX_UNKNOWN_MAX_AGE_SECONDS', 86400)),

    // #169: hard bounds for provider polling so a broken cursor cannot spin.
    // The lock must outlive the full worst-case run (time budget + one call
    // timeout); a shorter configured value is clamped by the service too.
    'polling' => [
        'max_pages' => max(1, (int) env('SHIPPING_POLLING_MAX_PAGES', 1000)),
        'time_budget_seconds' => $pollingTimeBudget,
        'provider_call_timeout_seconds' => $pollingCallTimeout,
        'lock_ttl_seconds' => max(
            $pollingTimeBudget + $pollingCallTimeout + 1,
            (int) env('SHIPPING_POLLING_LOCK_TTL_SECONDS', $pollingTimeBudget + $pollingCallTimeout + 60)
        ),
    ],

    'providers' => [
        'null' => [
            // Local placeholder only: no outbound calls, no capabilities.
            'adapter' => App\Services\Shipment\Providers\NullCarrierGateway::class,
            'mode' => env('SHIPPING_NULL_MODE', 'sandbox'),
            // Non-secret identity, stable across credential rotation.
            'account_code' => env('SHIPPING_NULL_ACCOUNT_CODE', 'null-local'),
            // Customer-facing service level only; never a carrier brand.
            'service_level' => 'standard',
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
        'internal' => [
            // Built-in local delivery: no outbound calls, no env credentials,
            // no webhook secret and no database-backed provider record.
            'adapter' => App\Services\Shipment\Providers\InternalDeliveryGateway::class,
            'mode' => 'internal',
            'account_code' => 'internal-local',
            // Customer-facing service level only; never a carrier brand.
            'service_level' => 'standard',
            'channels' => ['web', 'mobile'],
            'geographies' => ['EG'],
            'modes' => [
                'internal' => [
                    'enabled' => true,
                    'base_url' => 'internal://local-delivery',
                    // Factory requires a non-empty map; this is a local marker,
                    // never an external credential or secret.
                    'credentials' => ['local' => 'internal'],
                    'timeout_seconds' => 5,
                    'retry' => [
                        'attempts' => 1,
                        'backoff_ms' => 0,
                        'max_backoff_ms' => 0,
                    ],
                    'queues' => [
                        'dispatch' => 'shipping.internal.dispatch',
                        'webhook' => 'shipping.internal.webhook',
                    ],
                    'webhook' => [
                        // Version marker only: no secret and no inbox consumer.
                        'version' => 'internal',
                    ],
                    'capability_verification' => [
                        'create_shipment' => [
                            'verified_at' => '2026-11-22T00:00:00Z',
                            'evidence' => 'built-in internal delivery adapter',
                        ],
                        'cancel_shipment' => [
                            'verified_at' => '2026-11-22T00:00:00Z',
                            'evidence' => 'built-in internal delivery adapter',
                        ],
                    ],
                    'status_map' => [
                        'version' => 1,
                        'status_map_owner' => 'shipment-service',
                        'statuses' => [],
                    ],
                ],
            ],
        ],
    ],
];

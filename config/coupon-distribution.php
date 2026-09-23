<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Coupon Distribution (RabbitMQ event backbone)
    |--------------------------------------------------------------------------
    |
    | Tuning knobs for candidate fan-out, retries, and discovery caching.
    | RabbitMQ carries transport; MySQL stays the business truth; the
    | EligibilityEngine stays the sole eligibility authority.
    |
    */

    // Candidate fan-out chunk size (users per distribution chunk message).
    'chunk_size' => (int) env('COUPON_DISTRIBUTION_CHUNK_SIZE', 500),

    // Default cap for manual distribution audiences (admin may lower it).
    'default_audience_cap' => (int) env('COUPON_DISTRIBUTION_AUDIENCE_CAP', 10000),

    // Hard ceiling for manual distribution audiences.
    'max_audience_cap' => (int) env('COUPON_DISTRIBUTION_MAX_AUDIENCE_CAP', 100000),

    // Outbox publisher sweep: max rows claimed per run.
    'outbox_batch_size' => (int) env('COUPON_OUTBOX_BATCH_SIZE', 100),

    // Outbox publisher sweep interval hint (scheduler runs every minute).
    'outbox_max_attempts' => (int) env('COUPON_OUTBOX_MAX_ATTEMPTS', 25),

    // Consumer retry policy (bounded). Attempts are counted in the message
    // headers; exhausted messages are rejected to the DLQ (never requeued).
    'max_attempts' => [
        'distribution' => (int) env('COUPON_DISTRIBUTION_MAX_ATTEMPTS', 5),
        'evaluation' => (int) env('COUPON_EVALUATION_MAX_ATTEMPTS', 5),
        'notifications' => (int) env('COUPON_NOTIFICATION_MAX_ATTEMPTS', 3),
    ],

    // Delayed-retry backoff (seconds per attempt, 1-based index; the last
    // value repeats when attempts exceed the list length).
    'retry_delays' => [30, 120, 300, 900, 1800],

    // Customer discovery cache (seconds). Scoped per user + tree versions.
    'available_cache_ttl' => (int) env('COUPON_AVAILABLE_CACHE_TTL', 60),
    'available_default_limit' => (int) env('COUPON_AVAILABLE_DEFAULT_LIMIT', 15),
    'available_max_limit' => (int) env('COUPON_AVAILABLE_MAX_LIMIT', 50),

    // Creation grace before a coupon may be treated as proven public by
    // the global fan-out (targeting is added after creation; fail-closed).
    'public_grace_minutes' => (int) env('COUPON_PUBLIC_GRACE_MINUTES', 15),

    // Admin permission required for manual distribution + run inspection.
    // Reuses the existing coupon permission convention (explicit, documented).
    'admin_permission' => env('COUPON_DISTRIBUTION_ADMIN_PERMISSION', 'update-coupon'),

    // Event-log / outbox retention (days) for the prune command.
    'retention_days' => (int) env('COUPON_EVENT_RETENTION_DAYS', 90),
];

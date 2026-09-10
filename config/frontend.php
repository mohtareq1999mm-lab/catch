<?php

return [
    'enabled' => env('FRONTEND_WEBHOOK_ENABLED', false),

    'url' => env('FRONTEND_WEBHOOK_URL'),

    'secret' => env('FRONTEND_WEBHOOK_SECRET'),

    'timeout' => (int) env('FRONTEND_WEBHOOK_TIMEOUT', 10),

    'retry_times' => (int) env('FRONTEND_WEBHOOK_RETRY_TIMES', 3),

    'retry_sleep' => (int) env('FRONTEND_WEBHOOK_RETRY_SLEEP', 1000),

    'version' => (int) env('FRONTEND_WEBHOOK_VERSION', 1),

    'user_agent' => env('FRONTEND_WEBHOOK_USER_AGENT', 'MeemCommerce-Webhook/1.0'),

    'queue' => env('FRONTEND_WEBHOOK_QUEUE', 'meem-high'),
];

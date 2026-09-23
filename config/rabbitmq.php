<?php

return [

    /*
    |--------------------------------------------------------------------------
    | RabbitMQ Event Backbone (Coupon Distribution)
    |--------------------------------------------------------------------------
    |
    | Dedicated messaging backbone for the Coupon Distribution architecture.
    | The existing Laravel `database` queue is untouched — RabbitMQ carries
    | ONLY coupon distribution domain events behind the CouponEventTransport
    | contract. Credentials come from the environment; nothing is hardcoded.
    |
    */

    'host' => env('RABBITMQ_HOST', '127.0.0.1'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'vhost' => env('RABBITMQ_VHOST', '/'),
    'username' => env('RABBITMQ_USERNAME', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),

    // AMQP (plain) vs AMQPS (TLS). AMQPS requires a reachable TLS endpoint;
    // certificate verification follows php-amqplib secure defaults.
    'tls' => env('RABBITMQ_TLS', false),

    'connection_timeout' => (int) env('RABBITMQ_CONNECTION_TIMEOUT', 5),
    'read_write_timeout' => (int) env('RABBITMQ_READ_WRITE_TIMEOUT', 10),
    'heartbeat' => (int) env('RABBITMQ_HEARTBEAT', 30),

    // Publisher confirms (mandatory): the transport waits for broker acks.
    'publisher_confirms' => env('RABBITMQ_PUBLISHER_CONFIRMS', true),
    'confirm_timeout' => (int) env('RABBITMQ_CONFIRM_TIMEOUT', 5),

    // Consumer prefetch (QoS). Per-queue overrides fall back to this default.
    'prefetch' => (int) env('RABBITMQ_PREFETCH', 10),

    // Consumer loop safety: max wall-clock seconds per consume invocation
    // (0 = unbounded; supervisors restart bounded workers for deploys).
    'consume_max_seconds' => (int) env('RABBITMQ_CONSUME_MAX_SECONDS', 3600),

    // Topology names. Centralized here — application code must use
    // RabbitMqTopology, never hardcoded exchange/queue strings.
    'exchange' => env('RABBITMQ_EXCHANGE', 'coupon.events'),
    'dlx_exchange' => env('RABBITMQ_DLX_EXCHANGE', 'coupon.dlx'),

    'queues' => [
        'distribution' => env('RABBITMQ_QUEUE_DISTRIBUTION', 'coupon.distribution'),
        'evaluation' => env('RABBITMQ_QUEUE_EVALUATION', 'coupon.evaluation'),
        'notifications' => env('RABBITMQ_QUEUE_NOTIFICATIONS', 'coupon.notifications'),
    ],
];

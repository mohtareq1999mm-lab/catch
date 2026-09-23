<?php

namespace App\Services\Coupon\Distribution\Messaging;

/**
 * Broker readiness probe for the coupon backbone.
 *
 * A down broker is a DEGRADED distribution plane, not a down application:
 * business transactions keep succeeding (outbox holds events pending), so
 * callers decide whether broker health gates their own readiness.
 */
class RabbitMqHealthService
{
    public function __construct(
        private readonly CouponEventTransport $transport,
    ) {}

    /**
     * @return array{reachable: bool, exchange: string, queues: array<string,string>, checked_at: string, error: ?string}
     */
    public function check(): array
    {
        $error = null;
        $reachable = false;

        try {
            $reachable = $this->transport->isHealthy();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $reachable = false;
        }

        return [
            'reachable' => $reachable,
            'exchange' => RabbitMqTopology::exchange(),
            'queues' => RabbitMqTopology::queues(),
            'checked_at' => now()->toIso8601String(),
            'error' => $reachable ? null : ($error ?? 'Broker unreachable or exchange missing.'),
        ];
    }
}

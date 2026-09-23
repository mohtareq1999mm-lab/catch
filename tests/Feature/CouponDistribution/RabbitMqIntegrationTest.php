<?php

namespace Tests\Feature\CouponDistribution;

use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Messaging\ConsumeResult;
use App\Services\Coupon\Distribution\Messaging\RabbitMqCouponEventTransport;
use Tests\TestCase;

/**
 * Live-broker contract tests. Skipped without a broker (CI default);
 * run with a local RabbitMQ to prove the real AMQP path end to end.
 */
class RabbitMqIntegrationTest extends TestCase
{
    private function transport(): ?RabbitMqCouponEventTransport
    {
        $transport = new RabbitMqCouponEventTransport();

        if (! $transport->isHealthy()) {
            return null;
        }

        return $transport;
    }

    public function test_topology_publish_consume_roundtrip()
    {
        $transport = $this->transport();

        if ($transport === null) {
            $this->markTestSkipped('No RabbitMQ broker reachable; live-broker contract not verified.');
        }

        $transport->declareTopology();
        $this->assertTrue($transport->isHealthy());

        $envelope = CouponEventEnvelope::create(
            CouponDistributionEvents::USER_EVALUATE, 1, ['probe' => true]
        );
        $transport->publish($envelope);

        $seen = [];
        $processed = $transport->consume('evaluation', function (array $message) use (&$seen, $envelope) {
            $parsed = CouponEventEnvelope::fromJson($message['body']);

            if ($parsed->eventId === $envelope->eventId) {
                $seen[] = $parsed->eventId;

                return ConsumeResult::STOP;
            }

            return ConsumeResult::ACK;
        }, 10, 15);

        $transport->close();

        $this->assertGreaterThanOrEqual(1, $processed);
        $this->assertContains($envelope->eventId, $seen);
    }
}

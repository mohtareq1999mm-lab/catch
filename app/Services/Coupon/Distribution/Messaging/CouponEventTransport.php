<?php

namespace App\Services\Coupon\Distribution\Messaging;

use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;

/**
 * Coupon domain-event transport contract.
 *
 * RabbitMQ is the production implementation. Tests and local environments
 * without a broker bind the fake — application code never touches AMQP
 * directly, so transport swaps cannot change business semantics.
 */
interface CouponEventTransport
{
    /**
     * Publish one envelope to the exchange under its routing key.
     *
     * Must be durable (persistent message) with publisher confirms where
     * supported. Throws BrokerUnreachableException when the broker cannot
     * accept the message — the caller (outbox publisher) keeps the row
     * pending for a later retry. Never silently drops.
     */
    public function publish(CouponEventEnvelope $envelope): void;

    /**
     * Consume messages from one logical queue.
     *
     * The handler receives the raw body + headers and returns one of the
     * ConsumeResult outcomes (acked / retried / dead-lettered). The
     * transport owns ack/nack/reject mechanics; the handler owns business
     * idempotency. Blocks until $maxMessages processed, $maxSeconds elapse,
     * or the callback signals stop.
     *
     * @param  callable(array{body: string, headers: array, redelivered: bool}): int  $handler
     */
    public function consume(string $queue, callable $handler, int $maxMessages = 0, int $maxSeconds = 0): int;

    /**
     * Declare the full topology (exchanges, queues, retry queues, DLQs,
     * bindings). Idempotent — safe to run on every deploy.
     */
    public function declareTopology(): void;

    /**
     * Lightweight readiness probe. Returns true only when a connection can
     * be opened AND the primary exchange exists.
     */
    public function isHealthy(): bool;

    public function close(): void;
}

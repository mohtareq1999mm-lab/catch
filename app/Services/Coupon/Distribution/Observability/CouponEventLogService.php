<?php

namespace App\Services\Coupon\Distribution\Observability;

use App\Enums\CouponEventStatus;
use App\Models\CouponEventLog;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Messaging\RabbitMqTopology;

/**
 * Durable coupon event audit. Answers "coupon 123 stopped for user 456 —
 * where did it stop?" via (correlation_id, run_id, event_id) tracing.
 * No PII beyond identifiers; payloads stay in the outbox, never here.
 */
class CouponEventLogService
{
    public function recordPublished(CouponEventEnvelope $envelope): CouponEventLog
    {
        return CouponEventLog::query()->updateOrCreate(
            ['event_id' => $envelope->eventId],
            [
                'event_type' => $envelope->eventType,
                'aggregate_type' => $envelope->aggregateType,
                'aggregate_id' => $envelope->aggregateId !== null ? (string) $envelope->aggregateId : null,
                'user_id' => $envelope->userId,
                'distribution_run_id' => $envelope->distributionRunId,
                'correlation_id' => $envelope->correlationId,
                'causation_id' => $envelope->causationId,
                'status' => CouponEventStatus::PUBLISHED,
                'routing_key' => RabbitMqTopology::routingKeyFor($envelope->eventType),
                'occurred_at' => $envelope->occurredAt,
                'published_at' => $envelope->publishedAt ?? now(),
            ]
        );
    }

    public function markProcessing(string $eventId, string $consumer, string $queue, int $attempt): void
    {
        $this->touch($eventId, [
            'status' => CouponEventStatus::PROCESSING,
            'consumer' => $consumer,
            'queue' => $queue,
            'attempt' => $attempt,
            'consumed_at' => now(),
        ]);
    }

    public function markCompleted(string $eventId, int $durationMs, ?array $metadata = null): void
    {
        $this->touch($eventId, [
            'status' => CouponEventStatus::COMPLETED,
            'completed_at' => now(),
            'duration_ms' => $durationMs,
            'error_code' => null,
            'error_message' => null,
            'metadata' => $metadata,
        ]);
    }

    public function markRetrying(string $eventId, string $errorCode, string $errorMessage, int $nextAttempt): void
    {
        $this->touch($eventId, [
            'status' => CouponEventStatus::RETRYING,
            'attempt' => $nextAttempt,
            'error_code' => substr($errorCode, 0, 120),
            'error_message' => substr($errorMessage, 0, 500),
        ]);
    }

    public function markFailed(string $eventId, string $errorCode, string $errorMessage, int $durationMs): void
    {
        $this->touch($eventId, [
            'status' => CouponEventStatus::FAILED,
            'failed_at' => now(),
            'duration_ms' => $durationMs,
            'error_code' => substr($errorCode, 0, 120),
            'error_message' => substr($errorMessage, 0, 500),
        ]);
    }

    public function markDeadLettered(string $eventId, string $errorCode, string $errorMessage): void
    {
        $this->touch($eventId, [
            'status' => CouponEventStatus::DEAD_LETTERED,
            'failed_at' => now(),
            'error_code' => substr($errorCode, 0, 120),
            'error_message' => substr($errorMessage, 0, 500),
        ]);
    }

    /**
     * Trace one lifecycle: every event for a correlation id, ordered.
     *
     * @return list<CouponEventLog>
     */
    public function traceByCorrelation(string $correlationId): array
    {
        return CouponEventLog::query()
            ->where('correlation_id', $correlationId)
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function touch(string $eventId, array $attributes): void
    {
        CouponEventLog::query()->where('event_id', $eventId)->update(
            $attributes + ['updated_at' => now()]
        );
    }
}

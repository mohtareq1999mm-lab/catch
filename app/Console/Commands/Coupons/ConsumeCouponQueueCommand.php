<?php

namespace App\Console\Commands\Coupons;

use App\Enums\CouponEventStatus;
use App\Models\CouponEventLog;
use App\Services\Coupon\Distribution\Consumers\DistributionChunkHandler;
use App\Services\Coupon\Distribution\Consumers\DistributionStartHandler;
use App\Services\Coupon\Distribution\Consumers\NotificationRequestHandler;
use App\Services\Coupon\Distribution\Consumers\PoisonMessageException;
use App\Services\Coupon\Distribution\Consumers\UserEvaluateHandler;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Events\InvalidCouponEventException;
use App\Services\Coupon\Distribution\Messaging\BrokerUnreachableException;
use App\Services\Coupon\Distribution\Messaging\ConsumeResult;
use App\Services\Coupon\Distribution\Messaging\CouponEventTransport;
use App\Services\Coupon\Distribution\Messaging\RabbitMqTopology;
use App\Services\Coupon\Distribution\Observability\CouponEventLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * RabbitMQ work-queue consumer for coupon distribution.
 *
 * One process per logical queue (distribution|evaluation|notifications),
 * supervised (see deploy/supervisor/laravel-coupon-consumers.conf).
 * Envelope validation failures are poison: logged + dead-lettered, never
 * retried. Business exceptions retry bounded, then DLQ. Every outcome is
 * mirrored in the coupon event log for operator tracing.
 */
class ConsumeCouponQueueCommand extends Command
{
    protected $signature = 'coupon:consume
        {--queue= : Logical queue: distribution|evaluation|notifications}
        {--max-messages=0 : Stop after N messages (0 = unbounded)}
        {--max-seconds=0 : Stop after N seconds (0 = unbounded)}';

    protected $description = 'Consume one coupon RabbitMQ work queue with explicit ack, bounded retry, and DLQ routing.';

    public function handle(
        CouponEventTransport $transport,
        CouponEventLogService $eventLog,
        DistributionStartHandler $start,
        DistributionChunkHandler $chunk,
        UserEvaluateHandler $evaluate,
        NotificationRequestHandler $notify,
    ): int {
        $queue = (string) $this->option('queue');

        if (! in_array($queue, RabbitMqTopology::consumers(), true)) {
            $this->error('Unknown queue. Use: '.implode('|', RabbitMqTopology::consumers()));

            return self::FAILURE;
        }

        $handlers = [
            CouponDistributionEvents::DISTRIBUTION_START => $start,
            CouponDistributionEvents::DISTRIBUTION_CHUNK => $chunk,
            CouponDistributionEvents::USER_EVALUATE => $evaluate,
            CouponDistributionEvents::NOTIFICATION_REQUESTED => $notify,
        ];

        try {
            $processed = $transport->consume(
                $queue,
                function (array $message) use ($queue, $handlers, $eventLog): int {
                    return $this->processOne($message, $queue, $handlers, $eventLog);
                },
                (int) $this->option('max-messages'),
                (int) $this->option('max-seconds') > 0
                    ? (int) $this->option('max-seconds')
                    : (int) config('rabbitmq.consume_max_seconds', 3600)
            );
        } catch (BrokerUnreachableException $e) {
            // Broker down is a degraded distribution plane, not a crash:
            // exit non-zero with a one-line reason (no traceback) so
            // supervisors back off and the outbox keeps events pending.
            $this->error('RabbitMQ unreachable — outbox holds events pending: '.$e->getMessage());
            Log::warning('coupon.consumer.broker_unreachable', ['queue' => $queue]);

            return self::FAILURE;
        }

        $this->info("Processed {$processed} message(s) from [{$queue}].");

        return self::SUCCESS;
    }

    /**
     * @param  array{body: string, headers: array, redelivered: bool}  $message
     */
    public function processOne(array $message, string $queue, array $handlers, CouponEventLogService $eventLog): int
    {
        $started = microtime(true);
        $attempt = max(1, (int) ($message['headers']['x-attempt'] ?? 1));

        try {
            $envelope = CouponEventEnvelope::fromJson($message['body']);
        } catch (InvalidCouponEventException $e) {
            Log::warning('coupon.consumer.poison_message', [
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);

            return ConsumeResult::DEAD_LETTER;
        }

        // Consumer-side event_id idempotency: a redelivery of an already
        // COMPLETED event (crash between handler commit and ack) is a
        // no-op ack, never a second business effect.
        if ($this->alreadyCompleted($envelope)) {
            return ConsumeResult::ACK;
        }

        $eventLog->markProcessing(
            $envelope->eventId,
            $queue,
            RabbitMqTopology::queueFor($queue),
            $attempt
        );

        $handler = $handlers[$envelope->eventType] ?? null;

        if ($handler === null) {
            $eventLog->markFailed($envelope->eventId, 'no_handler', 'No handler bound for '.$envelope->eventType, $this->elapsedMs($started));

            return ConsumeResult::DEAD_LETTER;
        }

        try {
            $handler->handle($envelope);
            $eventLog->markCompleted($envelope->eventId, $this->elapsedMs($started));

            return ConsumeResult::ACK;
        } catch (PoisonMessageException|InvalidCouponEventException $e) {
            $eventLog->markDeadLettered($envelope->eventId, get_class($e), $e->getMessage());

            return ConsumeResult::DEAD_LETTER;
        } catch (\Throwable $e) {
            $max = RabbitMqTopology::maxAttemptsFor($queue);

            if ($attempt >= $max) {
                $eventLog->markDeadLettered($envelope->eventId, get_class($e), $e->getMessage());
                $this->markRecipientPermanent($envelope, $e);
                $this->maybeFinishRun($envelope);
                Log::error('coupon.consumer.exhausted_to_dlq', [
                    'event_id' => $envelope->eventId,
                    'queue' => $queue,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                return ConsumeResult::DEAD_LETTER;
            }

            $eventLog->markRetrying($envelope->eventId, get_class($e), $e->getMessage(), $attempt + 1);
            Log::warning('coupon.consumer.retrying', [
                'event_id' => $envelope->eventId,
                'queue' => $queue,
                'attempt' => $attempt,
                'error' => $e->getMessage(),
            ]);

            return ConsumeResult::RETRY;
        }
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    private function alreadyCompleted(CouponEventEnvelope $envelope): bool
    {
        return CouponEventLog::query()
            ->where('event_id', $envelope->eventId)
            ->where('status', CouponEventStatus::COMPLETED->value)
            ->exists();
    }

    /**
     * Terminal failure bookkeeping so the run can converge to failed and
     * operators see the recipient-level reason (the message itself is gone
     * to the DLQ; the event log keeps the failure reason).
     */
    private function markRecipientPermanent(CouponEventEnvelope $envelope, \Throwable $e): void
    {
        try {
            $recipientId = $envelope->payload['recipient_id'] ?? null;

            if ($recipientId === null) {
                return;
            }

            \App\Models\CouponDistributionRecipient::query()
                ->where('id', (int) $recipientId)
                ->update([
                    'status' => \App\Enums\CouponDistributionRecipientStatus::FAILED_PERMANENT->value,
                    'error' => substr($e->getMessage(), 0, 500),
                    'updated_at' => now(),
                ]);

            $runId = $envelope->payload['run_id'] ?? null;

            if ($runId !== null) {
                \App\Models\CouponDistributionRun::query()
                    ->where('id', (int) $runId)
                    ->increment('failed_count');
            }

            $this->reopenNotifyPending($envelope);
        } catch (\Throwable) {
            // Bookkeeping must never break the DLQ path.
        }
    }

    /**
     * A dead-lettered notification request must not wedge the user in
     * NOTIFY_PENDING forever: release back to ELIGIBLE so a future run
     * (new tree version or explicit re-run) can notify.
     */
    private function reopenNotifyPending(CouponEventEnvelope $envelope): void
    {
        try {
            if ($envelope->eventType !== CouponDistributionEvents::NOTIFICATION_REQUESTED) {
                return;
            }

            \App\Models\CouponDistributionUserState::query()
                ->where('coupon_id', (int) ($envelope->payload['coupon_id'] ?? 0))
                ->where('user_id', (int) ($envelope->payload['user_id'] ?? $envelope->userId ?? 0))
                ->where('state', \App\Enums\CouponDistributionUserState::NOTIFY_PENDING->value)
                ->update([
                    'state' => \App\Enums\CouponDistributionUserState::ELIGIBLE->value,
                    'updated_at' => now(),
                ]);
        } catch (\Throwable) {
        }
    }

    /**
     * Terminal bookkeeping may close the last open work — converge the run
     * so it cannot stick in RUNNING after its final failure.
     */
    private function maybeFinishRun(CouponEventEnvelope $envelope): void
    {
        try {
            $runId = $envelope->payload['run_id'] ?? $envelope->distributionRunId;

            if ($runId === null) {
                return;
            }

            $run = \App\Models\CouponDistributionRun::query()->find((int) $runId);

            if ($run === null) {
                return;
            }

            app(\App\Services\Coupon\Distribution\DistributionRunService::class)
                ->maybeFinishRun($run, $envelope);
        } catch (\Throwable) {
        }
    }
}

<?php

namespace App\Services\Coupon\Distribution\Outbox;

use App\Enums\CouponOutboxStatus;
use App\Jobs\Coupons\PublishCouponOutboxJob;
use App\Models\CouponOutbox;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Messaging\BrokerUnreachableException;
use App\Services\Coupon\Distribution\Messaging\CouponEventTransport;
use App\Services\Coupon\Distribution\Observability\CouponEventLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Transactional outbox: business rows + outbox row commit atomically, the
 * publisher delivers to RabbitMQ afterwards. Crash between commit and
 * publish leaves a `pending` row — the sweep republishes it. Duplicate
 * publishes are harmless: consumers are idempotent on event_id.
 *
 * Publisher claims carry a bounded lease (see LEASE_SECONDS): a crash
 * between claim and outcome leaves a stale `publishing` row that the sweep
 * reclaims; a live publisher's fresh claim is never stolen, so concurrent
 * publishers cannot double-publish the same row.
 */
class CouponOutboxService
{
    /**
     * Maximum age of a `publishing` claim before the sweep treats its owner
     * as dead and reclaims the row. Must comfortably exceed one publish
     * round-trip (broker confirms + DB writes finish in seconds).
     */
    public const LEASE_SECONDS = 300;

    public function __construct(
        private readonly CouponEventTransport $transport,
        private readonly CouponEventLogService $eventLog,
    ) {}

    /**
     * Record one envelope. Call INSIDE the business transaction; the row
     * commits atomically with the business state.
     */
    public function record(CouponEventEnvelope $envelope): CouponOutbox
    {
        return CouponOutbox::query()->create([
            'event_id' => $envelope->eventId,
            'event_type' => $envelope->eventType,
            'aggregate_type' => $envelope->aggregateType,
            'aggregate_id' => $envelope->aggregateId !== null ? (string) $envelope->aggregateId : null,
            'correlation_id' => $envelope->correlationId,
            'causation_id' => $envelope->causationId,
            'payload' => $envelope->toArray(),
            'status' => CouponOutboxStatus::PENDING,
            'attempts' => 0,
            'available_at' => now(),
        ]);
    }

    /**
     * Record inside the caller's transaction and schedule immediate
     * delivery via the database queue (after commit). The per-minute
     * scheduler sweep is the safety net for crashes between commit and
     * the immediate job.
     */
    public function recordAndDispatch(CouponEventEnvelope $envelope): CouponOutbox
    {
        $row = $this->record($envelope);

        PublishCouponOutboxJob::dispatch($row->event_id)->afterCommit();

        return $row;
    }

    /**
     * Record a delayed envelope: the row commits atomically with the
     * business state but becomes publishable only after $delaySeconds.
     * No immediate job is dispatched on purpose — the minutely sweep
     * (publishDue, available_at-gated) owns delivery. Used for
     * distribution-start fan-out (coupon valid now, notify later).
     */
    public function recordDelayed(CouponEventEnvelope $envelope, int $delaySeconds): CouponOutbox
    {
        $row = $this->record($envelope);

        if ($delaySeconds > 0) {
            $row->update(['available_at' => now()->addSeconds($delaySeconds)]);
        }

        return $row->fresh();
    }

    /**
     * Publish a single outbox row by event_id. Returns true when published.
     * Broker-down keeps the row pending (with backoff); poison payloads
     * mark the row failed after the attempt budget. A row freshly claimed
     * by a live publisher is left alone (returns false, no double publish);
     * a stale `publishing` claim (owner crashed) is reclaimed under the
     * lease and published.
     */
    public function publishOne(string $eventId): bool
    {
        /** @var CouponOutbox|null $row */
        $row = CouponOutbox::query()->where('event_id', $eventId)->first();

        if ($row === null || $row->status === CouponOutboxStatus::PUBLISHED) {
            return true;
        }

        if ($row->status === CouponOutboxStatus::FAILED) {
            return false;
        }

        // NOTE: available_at is a sweep hint only (publishDue filters on
        // it, including distribution-delay windows). publishOne is the
        // explicit recovery path and deliberately ignores it — see
        // recordDelayed().

        $leaseCutoff = now()->subSeconds(self::LEASE_SECONDS);

        $claimed = CouponOutbox::query()
            ->where('id', $row->id)
            ->where(function ($q) use ($leaseCutoff) {
                $q->where('status', CouponOutboxStatus::PENDING->value)
                    ->orWhere(function ($stale) use ($leaseCutoff) {
                        $stale->where('status', CouponOutboxStatus::PUBLISHING->value)
                            ->where('updated_at', '<', $leaseCutoff);
                    });
            })
            ->update([
                'status' => CouponOutboxStatus::PUBLISHING->value,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $row->refresh();

        try {
            $envelope = CouponEventEnvelope::fromArray($row->payload);
            $envelope->attempt = max(1, $row->attempts);
            $envelope->publishedAt = now()->toIso8601String();

            $this->transport->publish($envelope);

            $row->update([
                'status' => CouponOutboxStatus::PUBLISHED,
                'published_at' => now(),
                'last_error' => null,
            ]);

            $envelope->publishedAt ??= now()->toIso8601String();
            $this->eventLog->recordPublished($envelope);

            return true;
        } catch (BrokerUnreachableException $e) {
            // Broker down: stay pending with backoff — NEVER failed.
            $row->update([
                'status' => CouponOutboxStatus::PENDING,
                'available_at' => now()->addSeconds($this->backoffFor($row->attempts)),
                'last_error' => substr($e->getMessage(), 0, 500),
            ]);

            Log::warning('coupon.outbox.broker_unreachable', [
                'event_id' => $eventId,
                'attempt' => $row->attempts,
            ]);

            return false;
        } catch (\Throwable $e) {
            $max = (int) config('coupon-distribution.outbox_max_attempts', 25);

            $row->update([
                'status' => $row->attempts >= $max ? CouponOutboxStatus::FAILED : CouponOutboxStatus::PENDING,
                'available_at' => now()->addSeconds($this->backoffFor($row->attempts)),
                'last_error' => substr(get_class($e).': '.$e->getMessage(), 0, 500),
            ]);

            Log::error('coupon.outbox.publish_failed', [
                'event_id' => $eventId,
                'attempt' => $row->attempts,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Sweep recoverable rows (scheduler safety net): due pending rows plus
     * stale `publishing` claims whose owner died holding the lease.
     * Returns published count.
     */
    public function publishDue(int $batchSize): int
    {
        $leaseCutoff = now()->subSeconds(self::LEASE_SECONDS);

        $ids = CouponOutbox::query()
            ->where(function ($q) use ($leaseCutoff) {
                $q->where(function ($due) {
                    $due->where('status', CouponOutboxStatus::PENDING->value)
                        ->where('available_at', '<=', now());
                })->orWhere(function ($stale) use ($leaseCutoff) {
                    $stale->where('status', CouponOutboxStatus::PUBLISHING->value)
                        ->where('updated_at', '<', $leaseCutoff);
                });
            })
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('event_id');

        $published = 0;

        foreach ($ids as $eventId) {
            if ($this->publishOne((string) $eventId)) {
                $published++;
            }
        }

        return $published;
    }

    private function backoffFor(int $attempts): int
    {
        return match (true) {
            $attempts <= 1 => 30,
            $attempts <= 3 => 120,
            $attempts <= 6 => 600,
            default => 1800,
        };
    }
}

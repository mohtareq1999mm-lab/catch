<?php

declare(strict_types=1);

namespace App\Services\Shipment\Outbox;

use App\Audit\ActivityAuditService;
use App\Enums\Shipping\ShippingOutboxStatus;
use App\Jobs\Shipping\ProcessShippingOutboxJob;
use App\Models\Shipping\ShippingOutbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShippingOutboxService
{
    public const LEASE_SECONDS = 300;

    public function __construct(
        private readonly ShippingOutboxProcessor $processor,
        private readonly ShippingOutboxReconciler $reconciler,
    ) {}

    public function record(ShippingOutboxOperation $operation): ShippingOutbox
    {
        ShippingOutboxPayload::assertIdentifiersOnly($operation->payload);

        $attributes = [
            'operation' => $operation->operation->value,
            'aggregate_type' => $operation->aggregateType,
            'aggregate_id' => $operation->aggregateId,
            'idempotency_key' => $operation->idempotencyKey,
        ];
        $requestIdentity = [
            'operation' => $operation->operation->value,
            'aggregate_type' => $operation->aggregateType,
            'aggregate_id' => $operation->aggregateId,
            'authorized_request_id' => $operation->authorizedRequestId,
        ];

        $existing = ShippingOutbox::query()->where($attributes)->first();
        if ($existing !== null) {
            return $this->assertRecordedRequest($existing, $operation);
        }

        // #184 AC4: a version bump is only valid for a distinct authorized
        // business request. Reusing a request identity at another version is
        // rejected in application code and by the DB unique index.
        $sameRequest = ShippingOutbox::query()->where($requestIdentity)->first();
        if ($sameRequest !== null) {
            throw ValidationException::withMessages([
                'version' => 'An authorized request cannot create a new outbox version.',
            ]);
        }

        $versions = ShippingOutbox::query()
            ->where([
                'operation' => $operation->operation->value,
                'aggregate_type' => $operation->aggregateType,
                'aggregate_id' => $operation->aggregateId,
            ])
            ->pluck('idempotency_key')
            ->map(static fn (string $key): int => (int) substr((string) strrchr($key, ':'), 1))
            ->all();

        if ($operation->version > 1 && ! in_array($operation->version - 1, $versions, true)) {
            throw ValidationException::withMessages([
                'version' => 'Outbox versions must be created sequentially.',
            ]);
        }

        $values = $attributes + $requestIdentity + [
            'provider_code' => $operation->providerCode,
            'provider_mode' => $operation->providerMode,
            'payload' => json_encode($operation->payload, JSON_THROW_ON_ERROR),
            'status' => ShippingOutboxStatus::PENDING->value,
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            // MySQL suppresses duplicate conflicts here and returns 0, so both
            // insertOrIgnore() and the unique-violation path must re-read and
            // verify the winner before treating the row as this request's own.
            ShippingOutbox::query()->insertOrIgnore($values);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
        }

        return $this->assertRecordedRequest(
            ShippingOutbox::query()->where($attributes)->first(),
            $operation,
        );
    }

    private function assertRecordedRequest(?ShippingOutbox $row, ShippingOutboxOperation $operation): ShippingOutbox
    {
        if ($row === null) {
            // A concurrent writer can win a version key or the authorized
            // request key and leave no row for this version to re-read.
            throw ValidationException::withMessages([
                'version' => 'The outbox version could not be recorded for this authorized request.',
            ]);
        }

        if ($row->authorized_request_id !== $operation->authorizedRequestId) {
            throw ValidationException::withMessages([
                'authorized_request_id' => 'This outbox version belongs to a different authorized request.',
            ]);
        }

        return $row;
    }

    public function recordAndDispatch(ShippingOutboxOperation $operation): ShippingOutbox
    {
        $row = $this->record($operation);

        if (! $this->isConfigured()) {
            Log::error('shipping.outbox.not_configured', [
                'outbox_id' => $row->id,
                'operation' => $row->operation,
                'aggregate_type' => $row->aggregate_type,
                'aggregate_id' => $row->aggregate_id,
            ]);

            return $row;
        }

        if (in_array($row->status, [ShippingOutboxStatus::PENDING, ShippingOutboxStatus::UNKNOWN], true)) {
            ProcessShippingOutboxJob::dispatch($row->id)->afterCommit();
        }

        return $row;
    }

    public function processById(int $id): bool
    {
        $row = ShippingOutbox::query()->find($id);

        return $row === null ? true : $this->processRow($row);
    }

    public function processOne(string $idempotencyKey, string $operation, string $aggregateType, string $aggregateId): bool
    {
        $row = ShippingOutbox::query()->where([
            'idempotency_key' => $idempotencyKey,
            'operation' => $operation,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
        ])->first();

        return $row === null ? false : $this->processRow($row);
    }

    /**
     * #178: manual requeue is an explicit operator action. It requires a
     * non-empty reason and an authenticated actor, and writes an audit record
     * with the before/after state. It never runs the provider inline.
     */
    public function requeue(ShippingOutbox $row, int $actorId, string $reason): ShippingOutbox
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required for manual requeue.']);
        }
        if (mb_strlen($reason) > 400) {
            throw ValidationException::withMessages(['reason' => 'Reason must not exceed 400 characters.']);
        }
        if ($row->status === ShippingOutboxStatus::SUCCEEDED) {
            throw ValidationException::withMessages(['outbox' => 'A succeeded outbox row cannot be requeued.']);
        }

        $before = $row->toArray();

        DB::transaction(function () use ($row, $actorId, $reason, $before) {
            $updated = ShippingOutbox::query()
                ->where('id', $row->id)
                ->whereNotIn('status', [ShippingOutboxStatus::SUCCEEDED->value])
                ->update([
                    'status' => ShippingOutboxStatus::PENDING->value,
                    'processing_started_at' => null,
                    'dispatch_started_at' => null,
                    'attempts' => 0,
                    'available_at' => now(),
                    'last_error' => 'manual requeue: '.$reason,
                    'updated_at' => now(),
                ]);

            // Do not write an audit event for a state change that did not
            // happen (for example a concurrent SUCCEEDED write).
            if ($updated === 0) {
                throw ValidationException::withMessages([
                    'outbox' => 'The outbox row changed concurrently and was not requeued.',
                ]);
            }

            ActivityAuditService::recordSubject(
                subjectType: ShippingOutbox::class,
                subjectId: (int) $row->id,
                event: 'shipping.outbox.requeued',
                logName: 'shipping_outbox',
                description: 'Shipping outbox row manually requeued',
                old: $before,
                new: $row->fresh()?->toArray(),
                context: ['reason' => $reason, 'actor_id' => $actorId],
                causerId: $actorId,
                reason: $reason,
            );
        });

        Log::warning('shipping.outbox.manual_requeue', [
            'outbox_id' => $row->id,
            'actor_id' => $actorId,
            'reason' => $reason,
        ]);

        return $row->fresh();
    }

    public function processDue(int $batchSize): int
    {
        $leaseCutoff = now()->subSeconds(self::LEASE_SECONDS);
        $ids = ShippingOutbox::query()
            ->where(function ($q) use ($leaseCutoff) {
                $q->where(function ($due) {
                    $due->whereIn('status', [ShippingOutboxStatus::PENDING->value, ShippingOutboxStatus::UNKNOWN->value])
                        ->where('available_at', '<=', now());
                })->orWhere(function ($stale) use ($leaseCutoff) {
                    $stale->where('status', ShippingOutboxStatus::PROCESSING->value)
                        ->where('updated_at', '<', $leaseCutoff);
                });
            })
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('id');

        $processed = 0;
        foreach ($ids as $id) {
            if ($this->processById((int) $id)) {
                $processed++;
            }
        }

        return $processed;
    }

    private function processRow(ShippingOutbox $row): bool
    {
        if (in_array($row->status, [ShippingOutboxStatus::SUCCEEDED, ShippingOutboxStatus::FAILED, ShippingOutboxStatus::FAILED_UNKNOWN], true)) {
            return $row->status === ShippingOutboxStatus::SUCCEEDED;
        }

        if (! $this->isConfigured()) {
            Log::error('shipping.outbox.not_configured', [
                'outbox_id' => $row->id,
                'operation' => $row->operation,
            ]);

            return false;
        }

        if ($row->status === ShippingOutboxStatus::UNKNOWN) {
            return $this->reconcileUnknown($row);
        }

        // #178: an expired PROCESSING lease is only safe to reclaim when the
        // external call demonstrably never started. If a dispatch was marked,
        // the outcome is unknown and must be reconciled, never retried blindly.
        if ($row->status === ShippingOutboxStatus::PROCESSING) {
            return $this->recoverStaleProcessing($row);
        }

        $claimed = ShippingOutbox::query()
            ->where('id', $row->id)
            ->where('status', ShippingOutboxStatus::PENDING->value)
            ->where('available_at', '<=', now())
            ->update([
                'status' => ShippingOutboxStatus::PROCESSING->value,
                'attempts' => DB::raw('attempts + 1'),
                'processing_started_at' => now(),
                'dispatch_started_at' => null,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $row->refresh();

        // The claim token binds every later write to this worker's lease. A
        // stalled worker that wakes after lease takeover must not dispatch or
        // overwrite the newer state.
        $claim = $this->claimToken($row);
        $marked = $this->ownedUpdate($claim, ['dispatch_started_at' => now()]);
        if ($marked === 0) {
            return false;
        }
        $row->refresh();

        try {
            $result = $this->processor->process($row);
        } catch (\Throwable $e) {
            $result = $this->resultFromThrowable($e);
        }

        return $this->applyResult($row, $result, $claim);
    }

    /**
     * #178: recover an expired PROCESSING lease.
     *
     * The dispatch marker is authoritative:
     * - no marker => the provider call never started; safe to requeue.
     * - marker present => a call may be in flight or already delivered; the
     *   row becomes UNKNOWN and is reconciled by provider lookup.
     */
    private function recoverStaleProcessing(ShippingOutbox $row): bool
    {
        $leaseCutoff = now()->subSeconds(self::LEASE_SECONDS);

        if ($row->updated_at === null || $row->updated_at->gte($leaseCutoff)) {
            // Lease still held by another worker.
            return false;
        }

        if ($row->dispatch_started_at !== null) {
            $claimed = ShippingOutbox::query()
                ->where('id', $row->id)
                ->where('status', ShippingOutboxStatus::PROCESSING->value)
                ->where('updated_at', '<', $leaseCutoff)
                ->whereNotNull('dispatch_started_at')
                ->update([
                    'status' => ShippingOutboxStatus::UNKNOWN->value,
                    'processing_started_at' => null,
                    'last_error' => 'shipping.outbox.lease_expired_after_dispatch_start',
                    'available_at' => now()->addSeconds($this->backoffFor(max(1, (int) $row->attempts))),
                    'updated_at' => now(),
                ]);

            if ($claimed === 1) {
                Log::warning('shipping.outbox.lease_expired_after_dispatch_start', [
                    'outbox_id' => $row->id,
                    'attempts' => $row->attempts,
                ]);
            }

            return false;
        }

        $released = ShippingOutbox::query()
            ->where('id', $row->id)
            ->where('status', ShippingOutboxStatus::PROCESSING->value)
            ->where('updated_at', '<', $leaseCutoff)
            ->whereNull('dispatch_started_at')
            ->update([
                'status' => ShippingOutboxStatus::PENDING->value,
                'processing_started_at' => null,
                'available_at' => now(),
                'updated_at' => now(),
            ]);

        if ($released === 0) {
            return false;
        }

        Log::warning('shipping.outbox.lease_released_without_dispatch', [
            'outbox_id' => $row->id,
            'attempts' => $row->attempts,
        ]);

        return $this->processById((int) $row->id);
    }

    private function reconcileUnknown(ShippingOutbox $row): bool
    {
        if ($this->shouldEscalateUnknown($row)) {
            $this->escalateUnknown($row);
            return false;
        }

        $leaseCutoff = now()->subSeconds(self::LEASE_SECONDS);
        $claimed = ShippingOutbox::query()
            ->where('id', $row->id)
            ->where('status', ShippingOutboxStatus::UNKNOWN->value)
            ->where('available_at', '<=', now())
            ->where(function ($q) use ($leaseCutoff) {
                $q->whereNull('processing_started_at')
                    ->orWhere('updated_at', '<', $leaseCutoff);
            })
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                'processing_started_at' => now(),
                'available_at' => now()->addSeconds(self::LEASE_SECONDS),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $row->refresh();
        $claim = $this->claimToken($row);

        if ($this->shouldEscalateUnknown($row)) {
            $this->escalateUnknown($row, $claim);
            return false;
        }

        try {
            $result = $this->reconciler->reconcile($row);
        } catch (\Throwable $e) {
            Log::warning('shipping.outbox.reconcile_failed', [
                'outbox_id' => $row->id,
                'operation' => $row->operation,
                'aggregate_type' => $row->aggregate_type,
                'aggregate_id' => $row->aggregate_id,
                'error' => $e->getMessage(),
            ]);
            $this->applyResult($row, ShippingOutboxResult::unknown(substr(get_class($e).': '.$e->getMessage(), 0, 500)), $claim);
            return false;
        }

        if ($result->status === ShippingOutboxStatus::SUCCEEDED) {
            $this->applyResult($row, $result, $claim);
            return true;
        }

        if ($result->status === ShippingOutboxStatus::FAILED) {
            $this->applyResult($row, $result, $claim);
            return false;
        }

        if ($result->status === ShippingOutboxStatus::PENDING) {
            // Explicit reconciler signal: external operation proved absent; retry safely.
            $released = $this->ownedUpdate($claim, [
                'status' => ShippingOutboxStatus::PENDING->value,
                'processing_started_at' => null,
                'dispatch_started_at' => null,
                'available_at' => now(),
                'last_error' => $result->error !== null ? substr($result->error, 0, 500) : null,
                'updated_at' => now(),
            ], [ShippingOutboxStatus::UNKNOWN]);

            return $released > 0 ? $this->processRow($row->fresh()) : false;
        }

        if ($result->status === ShippingOutboxStatus::UNKNOWN) {
            $this->applyResult($row, $result, $claim);
            return false;
        }

        Log::warning('shipping.outbox.reconcile_unexpected_result', [
            'outbox_id' => $row->id,
            'status' => $result->status->value,
        ]);
        $this->applyResult($row, ShippingOutboxResult::unknown('shipping.outbox.reconcile_unexpected_result'), $claim);

        return false;
    }

    /**
     * #178: every post-claim write must be bound to the worker's lease. A
     * stalled worker that wakes after lease takeover must be a no-op.
     *
     * @param array{id:int,attempts:int,processing_started_at:?string} $claim
     * @param array<int,ShippingOutboxStatus> $statuses
     */
    private function ownedUpdate(array $claim, array $values, array $statuses = [ShippingOutboxStatus::PROCESSING, ShippingOutboxStatus::UNKNOWN]): int
    {
        $query = ShippingOutbox::query()
            ->where('id', $claim['id'])
            ->where('attempts', $claim['attempts']);

        if ($claim['processing_started_at'] === null) {
            $query->whereNull('processing_started_at');
        } else {
            $query->where('processing_started_at', $claim['processing_started_at']);
        }

        if ($statuses !== []) {
            $query->whereIn('status', array_map(
                static fn (ShippingOutboxStatus $status): string => $status->value,
                $statuses,
            ));
        }

        return $query->update($values);
    }

    /**
     * @return array{id:int,attempts:int,processing_started_at:?string}
     */
    private function claimToken(ShippingOutbox $row): array
    {
        return [
            'id' => (int) $row->id,
            'attempts' => (int) $row->attempts,
            'processing_started_at' => $row->processing_started_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array{id:int,attempts:int,processing_started_at:?string} $claim
     */
    private function applyResult(ShippingOutbox $row, ShippingOutboxResult $result, array $claim): bool
    {
        if ($result->status === ShippingOutboxStatus::SUCCEEDED) {
            $updated = $this->ownedUpdate($claim, [
                'status' => ShippingOutboxStatus::SUCCEEDED->value,
                'result' => $result->payload,
                'processed_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
            $row->refresh();
            if ($updated > 0) {
                Log::info('shipping.outbox.succeeded', ['outbox_id' => $row->id]);
            }
            return $updated > 0;
        }

        if ($result->status === ShippingOutboxStatus::UNKNOWN) {
            $this->persistUnknown($row, $result, $claim);
            return false;
        }

        $max = (int) config('shipping.outbox_max_attempts', 25);
        $status = $row->attempts >= $max ? ShippingOutboxStatus::FAILED : ShippingOutboxStatus::PENDING;

        $updated = $this->ownedUpdate($claim, [
            'status' => $status->value,
            'result' => $result->payload,
            'last_error' => substr((string) ($result->error ?? 'failed'), 0, 500),
            'available_at' => now()->addSeconds($this->backoffFor(max(1, (int) $row->attempts))),
            'processed_at' => $status === ShippingOutboxStatus::FAILED ? now() : $row->processed_at,
            'dispatch_started_at' => null,
            'updated_at' => now(),
        ]);
        $row->refresh();

        if ($updated > 0 && $status === ShippingOutboxStatus::FAILED) {
            Log::error('shipping.outbox.failed_terminal', [
                'outbox_id' => $row->id,
                'operation' => $row->operation,
                'attempts' => $row->attempts,
                'error' => $row->last_error,
            ]);
        }

        return false;
    }

    /**
     * @param array{id:int,attempts:int,processing_started_at:?string} $claim
     */
    private function persistUnknown(ShippingOutbox $row, ShippingOutboxResult $result, array $claim): void
    {
        if ($this->shouldEscalateUnknown($row)) {
            $this->ownedUpdate($claim, [
                'result' => $result->payload,
                'last_error' => substr((string) ($result->error ?? 'unknown'), 0, 500),
            ]);
            $row->refresh();
            $this->escalateUnknown($row, $claim);
            return;
        }

        $this->ownedUpdate($claim, [
            'status' => ShippingOutboxStatus::UNKNOWN->value,
            'result' => $result->payload,
            'last_error' => substr((string) ($result->error ?? 'unknown'), 0, 500),
            'available_at' => now()->addSeconds($this->backoffFor(max(1, (int) $row->attempts))),
            'updated_at' => now(),
        ]);
        $row->refresh();
    }

    private function shouldEscalateUnknown(ShippingOutbox $row): bool
    {
        $maxAttempts = max(1, (int) config('shipping.outbox_unknown_max_attempts', 10));
        $maxAgeSeconds = max(60, (int) config('shipping.outbox_unknown_max_age_seconds', 86400));
        $ageSeconds = $row->created_at?->diffInSeconds(now()) ?? 0;

        return $row->attempts >= $maxAttempts || $ageSeconds >= $maxAgeSeconds;
    }

    /**
     * @param array{id:int,attempts:int,processing_started_at:?string}|null $claim
     */
    private function escalateUnknown(ShippingOutbox $row, ?array $claim = null): void
    {
        if ($claim !== null) {
            $updated = $this->ownedUpdate($claim, [
                'status' => ShippingOutboxStatus::FAILED_UNKNOWN->value,
                'processed_at' => now(),
                'last_error' => substr((string) ($row->last_error ?: 'shipping.outbox.unknown_escalated'), 0, 500),
                'updated_at' => now(),
            ], [ShippingOutboxStatus::UNKNOWN]);
        } else {
            $updated = ShippingOutbox::query()
                ->where('id', $row->id)
                ->where('status', ShippingOutboxStatus::UNKNOWN->value)
                ->update([
                    'status' => ShippingOutboxStatus::FAILED_UNKNOWN->value,
                    'processed_at' => now(),
                    'last_error' => substr((string) ($row->last_error ?: 'shipping.outbox.unknown_escalated'), 0, 500),
                    'updated_at' => now(),
                ]);
        }
        $row->refresh();

        if ($updated === 0) {
            return;
        }

        Log::error('shipping.outbox.unknown_escalated', [
            'outbox_id' => $row->id,
            'operation' => $row->operation,
            'aggregate_type' => $row->aggregate_type,
            'aggregate_id' => $row->aggregate_id,
            'attempts' => $row->attempts,
        ]);
    }

    private function resultFromThrowable(\Throwable $e): ShippingOutboxResult
    {
        $message = substr(get_class($e).': '.$e->getMessage(), 0, 500);

        return $this->isAmbiguousExternalOutcome($e)
            ? ShippingOutboxResult::unknown($message)
            : ShippingOutboxResult::failed($message);
    }

    private function isAmbiguousExternalOutcome(\Throwable $e): bool
    {
        if ($e instanceof \Illuminate\Http\Client\ConnectionException) {
            $message = strtolower($e->getMessage());
            return str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'operation timed out');
        }

        $message = strtolower($e->getMessage());
        foreach (['ambiguous', 'unknown result', 'response not received', 'outcome unknown'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $sqlState === '23505' || $driverCode === 1062;
    }

    private function isConfigured(): bool
    {
        $processor = app(ShippingOutboxProcessor::class);
        $reconciler = app(ShippingOutboxReconciler::class);

        return ! $processor instanceof DefaultShippingOutboxProcessor
            && ! $reconciler instanceof DefaultShippingOutboxReconciler;
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

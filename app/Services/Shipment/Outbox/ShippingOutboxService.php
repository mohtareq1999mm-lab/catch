<?php

declare(strict_types=1);

namespace App\Services\Shipment\Outbox;

use App\Enums\Shipping\ShippingOutboxStatus;
use App\Jobs\Shipping\ProcessShippingOutboxJob;
use App\Models\Shipping\ShippingOutbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShippingOutboxService
{
    public const LEASE_SECONDS = 300;

    public function __construct(
        private readonly ShippingOutboxProcessor $processor,
        private readonly ShippingOutboxReconciler $reconciler,
    ) {}

    public function record(ShippingOutboxOperation $operation): ShippingOutbox
    {
        $attributes = [
            'operation' => $operation->operation,
            'aggregate_type' => $operation->aggregateType,
            'aggregate_id' => $operation->aggregateId,
            'idempotency_key' => $operation->idempotencyKey,
        ];

        $existing = ShippingOutbox::query()->where($attributes)->first();
        if ($existing !== null) {
            return $existing;
        }

        $values = $attributes + [
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
            $inserted = ShippingOutbox::query()->insertOrIgnore($values);
            if ($inserted === 1) {
                return ShippingOutbox::query()->where($attributes)->firstOrFail();
            }
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
        }

        return ShippingOutbox::query()->where($attributes)->firstOrFail();
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

        $leaseCutoff = now()->subSeconds(self::LEASE_SECONDS);
        $claimed = ShippingOutbox::query()
            ->where('id', $row->id)
            ->where(function ($q) use ($leaseCutoff) {
                $q->where(function ($pending) {
                    $pending->where('status', ShippingOutboxStatus::PENDING->value)
                        ->where('available_at', '<=', now());
                })->orWhere(function ($stale) use ($leaseCutoff) {
                    $stale->where('status', ShippingOutboxStatus::PROCESSING->value)
                        ->where('updated_at', '<', $leaseCutoff);
                });
            })
            ->update([
                'status' => ShippingOutboxStatus::PROCESSING->value,
                'attempts' => DB::raw('attempts + 1'),
                'processing_started_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $row->refresh();

        try {
            $result = $this->processor->process($row);
        } catch (\Throwable $e) {
            $result = $this->resultFromThrowable($e);
        }

        $this->applyResult($row, $result);

        return $result->status === ShippingOutboxStatus::SUCCEEDED;
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

        if ($this->shouldEscalateUnknown($row)) {
            $this->escalateUnknown($row);
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
            $this->applyResult($row, ShippingOutboxResult::unknown(substr(get_class($e).': '.$e->getMessage(), 0, 500)));
            return false;
        }

        if ($result->status === ShippingOutboxStatus::SUCCEEDED) {
            $this->applyResult($row, $result);
            return true;
        }

        if ($result->status === ShippingOutboxStatus::FAILED) {
            $this->applyResult($row, $result);
            return false;
        }

        if ($result->status === ShippingOutboxStatus::PENDING) {
            // Explicit reconciler signal: external operation proved absent; retry safely.
            $row->update([
                'status' => ShippingOutboxStatus::PENDING,
                'processing_started_at' => null,
                'available_at' => now(),
                'last_error' => $result->error !== null ? substr($result->error, 0, 500) : null,
                'updated_at' => now(),
            ]);

            return $this->processRow($row->fresh());
        }

        if ($result->status === ShippingOutboxStatus::UNKNOWN) {
            $this->applyResult($row, $result);
            return false;
        }

        Log::warning('shipping.outbox.reconcile_unexpected_result', [
            'outbox_id' => $row->id,
            'status' => $result->status->value,
        ]);
        $this->applyResult($row, ShippingOutboxResult::unknown('shipping.outbox.reconcile_unexpected_result'));

        return false;
    }

    private function applyResult(ShippingOutbox $row, ShippingOutboxResult $result): void
    {
        if ($result->status === ShippingOutboxStatus::SUCCEEDED) {
            $row->update([
                'status' => ShippingOutboxStatus::SUCCEEDED,
                'result' => $result->payload,
                'processed_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
            return;
        }

        if ($result->status === ShippingOutboxStatus::UNKNOWN) {
            $this->persistUnknown($row, $result);
            return;
        }

        $max = (int) config('shipping.outbox_max_attempts', 25);
        $status = $row->attempts >= $max ? ShippingOutboxStatus::FAILED : ShippingOutboxStatus::PENDING;

        $row->update([
            'status' => $status,
            'result' => $result->payload,
            'last_error' => substr((string) ($result->error ?? 'failed'), 0, 500),
            'available_at' => now()->addSeconds($this->backoffFor(max(1, (int) $row->attempts))),
            'processed_at' => $status === ShippingOutboxStatus::FAILED ? now() : $row->processed_at,
            'updated_at' => now(),
        ]);

        if ($status === ShippingOutboxStatus::FAILED) {
            Log::error('shipping.outbox.failed_terminal', [
                'outbox_id' => $row->id,
                'operation' => $row->operation,
                'attempts' => $row->attempts,
                'error' => $row->last_error,
            ]);
        }
    }

    private function persistUnknown(ShippingOutbox $row, ShippingOutboxResult $result): void
    {
        if ($this->shouldEscalateUnknown($row)) {
            $row->update([
                'result' => $result->payload,
                'last_error' => substr((string) ($result->error ?? 'unknown'), 0, 500),
            ]);
            $this->escalateUnknown($row->fresh());
            return;
        }

        $row->update([
            'status' => ShippingOutboxStatus::UNKNOWN,
            'result' => $result->payload,
            'last_error' => substr((string) ($result->error ?? 'unknown'), 0, 500),
            'available_at' => now()->addSeconds($this->backoffFor(max(1, (int) $row->attempts))),
            'updated_at' => now(),
        ]);
    }

    private function shouldEscalateUnknown(ShippingOutbox $row): bool
    {
        $maxAttempts = max(1, (int) config('shipping.outbox_unknown_max_attempts', 10));
        $maxAgeSeconds = max(60, (int) config('shipping.outbox_unknown_max_age_seconds', 86400));
        $ageSeconds = $row->created_at?->diffInSeconds(now()) ?? 0;

        return $row->attempts >= $maxAttempts || $ageSeconds >= $maxAgeSeconds;
    }

    private function escalateUnknown(ShippingOutbox $row): void
    {
        $row->update([
            'status' => ShippingOutboxStatus::FAILED_UNKNOWN,
            'processed_at' => now(),
            'last_error' => substr((string) ($row->last_error ?: 'shipping.outbox.unknown_escalated'), 0, 500),
            'updated_at' => now(),
        ]);

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

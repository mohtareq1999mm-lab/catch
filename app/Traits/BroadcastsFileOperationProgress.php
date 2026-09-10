<?php

namespace App\Traits;

use App\Events\FileOperationEvent;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Import;
use Throwable;

/**
 * Shared realtime-notification logic for long-running file operations
 * (imports / exports / bulk deletes).
 *
 * Guarantees:
 *  - Broadcasting failures NEVER propagate to the caller: a Pusher outage
 *    must never fail the underlying business operation.
 *  - Payloads are whitelisted, safe fields only.
 *  - Respects the project's existing Pusher gating conventions
 *    (app.env testing guard + config('shop.pusher.enabled')).
 *  - Terminal events are emitted at most once per process via the
 *    broadcastFileOperationTerminal() guard (retry attempts re-entering a
 *    terminal state are additionally blocked by job-level early returns).
 */
trait BroadcastsFileOperationProgress
{
    /**
     * Cached owner (created_by) of the current operation.
     */
    protected ?int $fileOperationOwnerId = null;

    /**
     * Per-process guard against duplicate terminal events.
     */
    protected bool $fileOperationTerminalEmitted = false;

    protected function shouldBroadcastFileOperation(): bool
    {
        if (config('app.env') === 'testing') {
            return false;
        }

        return config('shop.pusher.enabled', true) !== false;
    }

    protected function resolveFileOperationOwnerId(int $operationId): ?int
    {
        if ($this->fileOperationOwnerId !== null) {
            return $this->fileOperationOwnerId;
        }

        $this->fileOperationOwnerId = (int) Import::where('id', $operationId)->value('created_by') ?: null;

        return $this->fileOperationOwnerId;
    }

    /**
     * Emit a realtime progress update for a file operation.
     */
    protected function broadcastFileOperationProgress(
        string $eventName,
        string $kind,
        int $operationId,
        float $progress,
        int $processedRows,
        int $successRows,
        int $failedRows,
        ?int $totalRows = null,
        string $status = 'processing',
        array $extraPayload = [],
    ): void {
        $payload = array_merge([
            'kind' => $kind,
            'id' => $operationId,
            'status' => $status,
            'progress' => round(max(0.0, min($progress, 100.0)), 2),
            'processed_rows' => $processedRows,
            'success_rows' => $successRows,
            'failed_rows' => $failedRows,
        ], $extraPayload);

        if ($totalRows !== null) {
            $payload['total_rows'] = $totalRows;
        }

        $this->dispatchFileOperationEvent($eventName, $kind, $operationId, $payload);
    }

    /**
     * Emit a realtime terminal transition for a file operation.
     * Guaranteed to fire at most once per process.
     */
    protected function broadcastFileOperationTerminal(
        string $eventName,
        string $kind,
        int $operationId,
        string $status,
        bool $hasErrors = false,
        array $extraPayload = [],
    ): void {
        if ($this->fileOperationTerminalEmitted) {
            return;
        }

        $this->fileOperationTerminalEmitted = true;

        $this->dispatchFileOperationEvent($eventName, $kind, $operationId, array_merge([
            'kind' => $kind,
            'id' => $operationId,
            'status' => $status,
            'has_errors' => $hasErrors,
        ], $extraPayload));
    }

    /**
     * Dispatch the event while isolating every failure mode.
     * A broadcast problem is logged and reported; it never throws.
     */
    protected function dispatchFileOperationEvent(
        string $eventName,
        string $kind,
        int $operationId,
        array $payload,
    ): void {
        try {
            if (!$this->shouldBroadcastFileOperation()) {
                return;
            }

            $userId = $this->resolveFileOperationOwnerId($operationId);

            if ($userId === null) {
                Log::warning('file-operation.event.skipped', [
                    'event' => $eventName,
                    'kind' => $kind,
                    'operation_id' => $operationId,
                    'reason' => 'no_owner_user',
                ]);

                return;
            }

            FileOperationEvent::dispatch($userId, $eventName, $payload);

            Log::info('file-operation.event.dispatched', [
                'event' => $eventName,
                'kind' => $kind,
                'operation_id' => $operationId,
                'user_id' => $userId,
                'channel' => 'private-users.' . $userId,
            ]);
        } catch (Throwable $e) {
            Log::error('file-operation.event.broadcast_failed', [
                'event' => $eventName,
                'kind' => $kind,
                'operation_id' => $operationId,
                'error' => $e->getMessage(),
            ]);

            report($e);
        }
    }
}
